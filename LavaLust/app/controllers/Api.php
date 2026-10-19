<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api extends Controller
{
    private $origin;

    public function before_action()
    {
        $this->call->database();
        $this->call->library('session');
        $this->call->library('email_notification_service');
        $this->origin = getenv('FRONTEND_URL') ?: 'http://localhost:5173';
    }

    private function respond($data, $code = 200)
    {
        $this->response->with_cors($this->origin, 'GET, POST, PUT, PATCH, DELETE, OPTIONS', 'Content-Type, X-CSRF-Token', true);
        $this->response->send_json($data, $code);
    }

    private function error($message, $code = 400, $extra = [])
    {
        $payload = array_merge(['success' => false, 'error' => $message], $extra);
        $this->respond($payload, $code);
    }

    private function method_not_allowed($allowed = 'GET, OPTIONS')
    {
        if ($this->request->method(true) === 'OPTIONS') {
            $this->response->with_cors($this->origin, $allowed, 'Content-Type, X-CSRF-Token', true);
            $this->response->set_status_code(204)->send();
            return true;
        }
        return false;
    }

    private function get_request_data()
    {
        $json = $this->request->json(true);
        if (is_array($json) && $json !== []) {
            return $json;
        }

        $post = $this->request->post();
        if (is_array($post) && $post !== []) {
            return $post;
        }

        $raw = $this->request->raw_body();
        if (!$raw) {
            return [];
        }

        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    private function issue_csrf_token()
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set_userdata('api_csrf_token', $token);
        return $token;
    }

    private function require_csrf($data)
    {
        $method = $this->request->method(true);
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? null);
        $sessionToken = $this->session->userdata('api_csrf_token');
        if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
            $this->error('Invalid CSRF token.', 403);
            return false;
        }

        return true;
    }

    private function current_user()
    {
        $userId = (int) ($this->session->userdata('user_id') ?: 0);
        if ($userId <= 0) {
            return null;
        }

        return $this->db->table('users')
            ->select('id, username, email, role, is_active')
            ->where('id', $userId)
            ->limit(1)
            ->get();
    }

    private function require_auth($role = null)
    {
        $user = $this->current_user();
        if (!$user) {
            $this->error('Authentication required.', 401);
            return null;
        }

        if ((int) $user['is_active'] !== 1) {
            $this->error('Account is suspended.', 403);
            return null;
        }

        if ($role && $user['role'] !== $role) {
            $this->error('Access denied.', 403);
            return null;
        }

        return $user;
    }

    private function log_admin_action($adminUserId, $action, $entityType, $entityId, $details = null)
    {
        $this->db->table('activity_logs')->insert([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'details' => $details,
        ]);
    }

    private function generate_reference_no()
    {
        return 'ESP-' . date('Ymd') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function daterange($startDate, $endDate)
    {
        $dates = [];
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($start < $end) {
            $dates[] = $start->format('Y-m-d');
            $start->modify('+1 day');
        }
        if ($dates === []) {
            $dates[] = (new DateTime($startDate))->format('Y-m-d');
        }
        return $dates;
    }

    private function check_availability_or_error($serviceId, $checkInDate, $checkOutDate)
    {
        $dates = $this->daterange($checkInDate, $checkOutDate);
        foreach ($dates as $date) {
            $availability = $this->db->table('service_availability')
                ->where('service_id', $serviceId)
                ->where('available_date', $date)
                ->where('is_active', 1)
                ->limit(1)
                ->get();

            if (!$availability || ((int) $availability['booked_quantity'] >= (int) $availability['total_quantity'])) {
                $this->error('Service is unavailable for one or more selected dates.', 409, ['unavailable_date' => $date]);
                return false;
            }
        }
        return true;
    }

    private function apply_booking_quantity($serviceId, $checkInDate, $checkOutDate, $delta)
    {
        $dates = $this->daterange($checkInDate, $checkOutDate);
        foreach ($dates as $date) {
            $row = $this->db->table('service_availability')
                ->where('service_id', $serviceId)
                ->where('available_date', $date)
                ->limit(1)
                ->get();
            if (!$row) {
                continue;
            }
            $newValue = max(0, (int) $row['booked_quantity'] + $delta);
            $this->db->table('service_availability')
                ->where('id', (int) $row['id'])
                ->update([
                    'booked_quantity' => $newValue,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    private function queue_booking_email($booking, $type)
    {
        $user = $this->db->table('users')->where('id', (int) $booking['user_id'])->limit(1)->get();
        if (!$user) {
            return;
        }

        $subjectMap = [
            'submitted' => 'Booking submitted',
            'confirmed' => 'Booking confirmed',
            'rejected' => 'Booking rejected',
            'cancelled' => 'Booking cancelled',
            'completed' => 'Booking completed',
            'reminder' => 'Visit reminder',
        ];
        $subject = $subjectMap[$type] ?? 'Booking status update';
        $body = "Hello {$user['username']},\n\nBooking {$booking['reference_no']} is now {$booking['status']}.\nService ID: {$booking['service_id']}\nCheck-in: {$booking['check_in_date']}\nCheck-out: {$booking['check_out_date']}\n\nThank you,\nESPED Resort";

        $this->email_notification_service->queue_and_send([
            'booking_id' => (int) $booking['id'],
            'user_id' => (int) $booking['user_id'],
            'recipient_email' => $user['email'],
            'subject' => $subject,
            'body' => $body,
            'email_type' => $type,
        ]);
    }

    public function health()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }

        $this->respond([
            'status' => 'ok',
            'service' => 'esped-resort-api',
            'timestamp' => gmdate('c'),
        ]);
    }

    public function csrf()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }

        $this->respond([
            'success' => true,
            'csrf_token' => $this->issue_csrf_token(),
        ]);
    }

    public function register()
    {
        if ($this->method_not_allowed('POST, OPTIONS')) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $this->error('Username, valid email, and password (min 8 chars) are required.', 422);
            return;
        }

        $existing = $this->db->table('users')->where('email', $email)->limit(1)->get();
        if ($existing) {
            $this->error('Email already registered.', 409);
            return;
        }

        $this->db->table('users')->insert([
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'guest',
            'is_active' => 1,
        ]);

        $this->respond(['success' => true, 'message' => 'Guest account created.'], 201);
    }

    public function login()
    {
        if ($this->method_not_allowed('POST, OPTIONS')) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $loginAs = trim((string) ($data['login_as'] ?? 'guest'));

        if (!in_array($loginAs, ['guest', 'admin'], true)) {
            $this->error('Invalid Login as selection.', 422);
            return;
        }

        $user = $this->db->table('users')->where('email', $email)->limit(1)->get();
        if (!$user || !password_verify($password, $user['password'])) {
            $this->error('Invalid credentials.', 401);
            return;
        }

        if ($user['role'] !== $loginAs) {
            $this->error('Account does not match selected role.', 403);
            return;
        }

        if ((int) $user['is_active'] !== 1) {
            $this->error('Account is suspended.', 403);
            return;
        }

        $this->session->regenerate_on_login(true);
        $this->session->set_userdata([
            'user_id' => (int) $user['id'],
            'role' => $user['role'],
        ]);

        $this->respond([
            'success' => true,
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ]);
    }

    public function logout()
    {
        if ($this->method_not_allowed('POST, OPTIONS')) {
            return;
        }
        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }
        $this->session->sess_destroy();
        $this->respond(['success' => true, 'message' => 'Logged out.']);
    }

    public function me()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $user = $this->current_user();
        $this->respond([
            'authenticated' => (bool) $user,
            'user' => $user ? [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
            ] : null,
        ]);
    }

    public function update_account()
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $user = $this->require_auth('guest');
        if (!$user) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $username = trim((string) ($data['username'] ?? $user['username']));
        $email = trim((string) ($data['email'] ?? $user['email']));
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid account details.', 422);
            return;
        }

        $duplicate = $this->db->raw("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1", [$email, (int) $user['id']])->fetch(PDO::FETCH_ASSOC);
        if ($duplicate) {
            $this->error('Email is already in use.', 409);
            return;
        }

        $this->db->table('users')->where('id', (int) $user['id'])->update([
            'username' => $username,
            'email' => $email,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->respond(['success' => true, 'message' => 'Account updated.']);
    }

    public function update_password()
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $user = $this->require_auth('guest');
        if (!$user) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $current = (string) ($data['current_password'] ?? '');
        $next = (string) ($data['new_password'] ?? '');
        $fullUser = $this->db->table('users')->where('id', (int) $user['id'])->limit(1)->get();
        if (!$fullUser || !password_verify($current, $fullUser['password'])) {
            $this->error('Current password is incorrect.', 422);
            return;
        }
        if (strlen($next) < 8) {
            $this->error('New password must be at least 8 characters.', 422);
            return;
        }

        $this->db->table('users')->where('id', (int) $user['id'])->update([
            'password' => password_hash($next, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->respond(['success' => true, 'message' => 'Password updated.']);
    }

    public function home()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $about = $this->db->table('resort_info')->limit(1)->get();
        $featured = $this->db->table('services')
            ->select('id, name, service_type, description, price, capacity')
            ->where('is_active', 1)
            ->order_by('price', 'ASC')
            ->limit(6)
            ->get_all();

        $this->respond([
            'hero' => [
                'title' => 'Welcome to ESPED Resort',
                'subtitle' => 'Classic comfort in a peaceful destination.',
            ],
            'about_preview' => $about ? $about['description'] : '',
            'featured_services' => $featured,
        ]);
    }

    public function resort_info()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $info = $this->db->table('resort_info')->limit(1)->get();
        $this->respond(['resort_info' => $info ?: null]);
    }

    public function services()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $search = trim((string) ($this->request->get('search') ?? ''));
        $serviceType = trim((string) ($this->request->get('service_type') ?? ''));
        $sort = strtolower(trim((string) ($this->request->get('sort') ?? 'asc')));
        if (!in_array($sort, ['asc', 'desc'], true)) {
            $sort = 'asc';
        }

        $sql = "SELECT id, name, service_type, description, price, capacity, is_active FROM services WHERE is_active = 1";
        $params = [];
        if ($serviceType !== '') {
            $sql .= " AND service_type = ?";
            $params[] = $serviceType;
        }
        if ($search !== '') {
            $sql .= " AND (name LIKE ? OR description LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= " ORDER BY price " . strtoupper($sort);

        $services = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['services' => $services]);
    }

    public function recommendation()
    {
        if ($this->method_not_allowed('POST, OPTIONS')) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $maximumBudget = (float) ($data['maximum_budget'] ?? 0);
        $guests = (int) ($data['number_of_guests'] ?? 0);
        $preferences = trim((string) ($data['preferences'] ?? ''));

        if ($maximumBudget <= 0 || $guests <= 0) {
            $this->error('Maximum budget and number of guests are required.', 422);
            return;
        }

        $services = $this->db->raw(
            "SELECT id, name, service_type, description, price, capacity
             FROM services
             WHERE is_active = 1 AND price <= ? AND capacity >= ?
             ORDER BY price ASC",
            [$maximumBudget, $guests]
        )->fetchAll(PDO::FETCH_ASSOC);

        $source = 'fallback';
        $ranked = [];
        $aiApiKey = getenv('AI_API_KEY') ?: '';
        $aiApiUrl = getenv('AI_API_URL') ?: '';
        if ($aiApiKey !== '' && $aiApiUrl !== '' && $services !== []) {
            $payload = [
                'budget' => $maximumBudget,
                'guests' => $guests,
                'preferences' => $preferences,
                'services' => $services,
            ];
            $ch = curl_init($aiApiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $aiApiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && isset($decoded['recommendations']) && is_array($decoded['recommendations'])) {
                    $allowedById = [];
                    foreach ($services as $service) {
                        $allowedById[(int) $service['id']] = $service;
                    }
                    $rank = 1;
                    foreach ($decoded['recommendations'] as $item) {
                        $serviceId = (int) ($item['service_id'] ?? 0);
                        if (!isset($allowedById[$serviceId])) {
                            continue;
                        }
                        $service = $allowedById[$serviceId];
                        if ((float) $service['price'] > $maximumBudget || (int) $service['capacity'] < $guests) {
                            continue;
                        }
                        $ranked[] = [
                            'service' => $service,
                            'rank_order' => $rank,
                            'match_reason' => (string) ($item['match_reason'] ?? 'Matches your budget and guest count.'),
                        ];
                        $rank++;
                        if ($rank > 3) {
                            break;
                        }
                    }
                    if ($ranked !== []) {
                        $source = 'ai';
                    }
                }
            }
        }

        if ($ranked === []) {
            $preferenceTokens = preg_split('/\s+/', strtolower($preferences));
            $scored = [];
            foreach ($services as $service) {
                $text = strtolower($service['name'] . ' ' . $service['description'] . ' ' . $service['service_type']);
                $score = 0;
                foreach ($preferenceTokens as $token) {
                    if ($token !== '' && str_contains($text, $token)) {
                        $score++;
                    }
                }
                $scored[] = [
                    'service' => $service,
                    'score' => $score,
                ];
            }
            usort($scored, function ($a, $b) {
                if ($a['score'] === $b['score']) {
                    return (float) $a['service']['price'] <=> (float) $b['service']['price'];
                }
                return $b['score'] <=> $a['score'];
            });

            $rank = 1;
            foreach ($scored as $entry) {
                if ($rank > 3) {
                    break;
                }
                $ranked[] = [
                    'service' => $entry['service'],
                    'rank_order' => $rank,
                    'match_reason' => $entry['score'] > 0
                        ? 'Matched your preferences and budget.'
                        : 'Fits your budget and guest count.',
                ];
                $rank++;
            }
        }

        $user = $this->current_user();
        $this->db->table('recommendation_requests')->insert([
            'user_id' => $user ? (int) $user['id'] : null,
            'maximum_budget' => $maximumBudget,
            'number_of_guests' => $guests,
            'preferences' => $preferences,
            'response_source' => $source,
        ]);
        $requestId = (int) $this->db->last_id();

        foreach ($ranked as $item) {
            $this->db->table('recommendation_items')->insert([
                'request_id' => $requestId,
                'service_id' => (int) $item['service']['id'],
                'rank_order' => (int) $item['rank_order'],
                'match_reason' => $item['match_reason'],
            ]);
        }

        $this->respond([
            'success' => true,
            'request_id' => $requestId,
            'source' => $source,
            'items' => $ranked,
        ]);
    }

    public function create_booking()
    {
        if ($this->method_not_allowed('POST, OPTIONS')) {
            return;
        }
        $user = $this->require_auth('guest');
        if (!$user) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $serviceId = (int) ($data['service_id'] ?? 0);
        $checkIn = (string) ($data['check_in_date'] ?? '');
        $checkOut = (string) ($data['check_out_date'] ?? '');
        $guests = (int) ($data['guests'] ?? 0);
        $specialRequests = trim((string) ($data['special_requests'] ?? ''));

        if ($serviceId <= 0 || $checkIn === '' || $checkOut === '' || $guests <= 0) {
            $this->error('Service, dates, and guest count are required.', 422);
            return;
        }

        $service = $this->db->table('services')->where('id', $serviceId)->where('is_active', 1)->limit(1)->get();
        if (!$service) {
            $this->error('Service not found.', 404);
            return;
        }
        if ((int) $service['capacity'] < $guests) {
            $this->error('Guest count exceeds service capacity.', 422);
            return;
        }

        if (!$this->check_availability_or_error($serviceId, $checkIn, $checkOut)) {
            return;
        }

        $checkInDate = new DateTime($checkIn);
        $checkOutDate = new DateTime($checkOut);
        $nights = max(1, (int) $checkOutDate->diff($checkInDate)->format('%a'));
        $unitPrice = (float) $service['price'];
        $subtotal = $unitPrice * $nights;
        $totalAmount = $subtotal;

        $referenceNo = $this->generate_reference_no();
        $this->db->table('bookings')->insert([
            'user_id' => (int) $user['id'],
            'service_id' => $serviceId,
            'reference_no' => $referenceNo,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'guests' => $guests,
            'special_requests' => $specialRequests,
            'status' => 'Pending',
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'total_amount' => $totalAmount,
        ]);
        $bookingId = (int) $this->db->last_id();

        $booking = $this->db->table('bookings')->where('id', $bookingId)->limit(1)->get();
        $this->queue_booking_email($booking, 'submitted');

        $this->respond([
            'success' => true,
            'message' => 'Booking submitted and saved as Pending.',
            'booking' => $booking,
        ], 201);
    }

    public function my_bookings()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $user = $this->require_auth('guest');
        if (!$user) {
            return;
        }

        $rows = $this->db->raw(
            "SELECT b.*, s.name AS service_name, s.service_type
             FROM bookings b
             INNER JOIN services s ON s.id = b.service_id
             WHERE b.user_id = ?
             ORDER BY b.created_at DESC",
            [(int) $user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['bookings' => $rows]);
    }

    public function cancel_my_booking($bookingId)
    {
        if ($this->method_not_allowed('DELETE, OPTIONS')) {
            return;
        }
        $user = $this->require_auth('guest');
        if (!$user) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $booking = $this->db->table('bookings')->where('id', (int) $bookingId)->where('user_id', (int) $user['id'])->limit(1)->get();
        if (!$booking) {
            $this->error('Booking not found.', 404);
            return;
        }
        if ($booking['status'] !== 'Pending') {
            $this->error('Only Pending bookings can be cancelled by guests.', 422);
            return;
        }

        $this->db->table('bookings')->where('id', (int) $bookingId)->update([
            'status' => 'Cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $updated = $this->db->table('bookings')->where('id', (int) $bookingId)->limit(1)->get();
        $this->queue_booking_email($updated, 'cancelled');
        $this->respond(['success' => true, 'message' => 'Booking cancelled.']);
    }

    public function submit_feedback($bookingId)
    {
        if ($this->method_not_allowed('POST, OPTIONS')) {
            return;
        }
        $user = $this->require_auth('guest');
        if (!$user) {
            return;
        }

        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $booking = $this->db->table('bookings')->where('id', (int) $bookingId)->where('user_id', (int) $user['id'])->limit(1)->get();
        if (!$booking || $booking['status'] !== 'Completed') {
            $this->error('Feedback is allowed only after a completed visit.', 422);
            return;
        }

        $rating = (int) ($data['rating'] ?? 0);
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($rating < 1 || $rating > 5 || $comment === '') {
            $this->error('Rating (1-5) and comment are required.', 422);
            return;
        }

        $existing = $this->db->table('feedback')->where('booking_id', (int) $bookingId)->limit(1)->get();
        if ($existing) {
            $this->error('Feedback already submitted for this booking.', 409);
            return;
        }

        $this->db->table('feedback')->insert([
            'booking_id' => (int) $bookingId,
            'user_id' => (int) $user['id'],
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $this->respond(['success' => true, 'message' => 'Feedback submitted.']);
    }

    public function admin_dashboard()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }

        $pending = $this->db->raw("SELECT COUNT(*) AS total FROM bookings WHERE status = 'Pending'")->fetch(PDO::FETCH_ASSOC);
        $confirmed = $this->db->raw("SELECT COUNT(*) AS total FROM bookings WHERE status = 'Confirmed'")->fetch(PDO::FETCH_ASSOC);
        $guests = $this->db->raw("SELECT COUNT(*) AS total FROM users WHERE role = 'guest'")->fetch(PDO::FETCH_ASSOC);
        $revenue = $this->db->raw("SELECT COALESCE(SUM(total_amount), 0) AS total FROM bookings WHERE status = 'Completed'")->fetch(PDO::FETCH_ASSOC);
        $latestPending = $this->db->raw(
            "SELECT b.id, b.reference_no, b.check_in_date, b.check_out_date, b.guests, b.total_amount, u.username, s.name AS service_name
             FROM bookings b
             INNER JOIN users u ON u.id = b.user_id
             INNER JOIN services s ON s.id = b.service_id
             WHERE b.status = 'Pending'
             ORDER BY b.created_at DESC
             LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        $trend = $this->db->raw(
            "SELECT DATE(created_at) AS report_date, COUNT(*) AS bookings, COALESCE(SUM(total_amount), 0) AS revenue
             FROM bookings
             GROUP BY DATE(created_at)
             ORDER BY report_date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->respond([
            'cards' => [
                'pending' => (int) $pending['total'],
                'confirmed' => (int) $confirmed['total'],
                'guests' => (int) $guests['total'],
                'revenue' => (float) $revenue['total'],
            ],
            'chart' => $trend,
            'latest_pending' => $latestPending,
        ]);
    }

    public function admin_bookings()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }

        $search = trim((string) ($this->request->get('search') ?? ''));
        $status = trim((string) ($this->request->get('status') ?? ''));
        $params = [];
        $sql = "SELECT b.*, u.username, u.email, s.name AS service_name, s.service_type
                FROM bookings b
                INNER JOIN users u ON u.id = b.user_id
                INNER JOIN services s ON s.id = b.service_id
                WHERE 1=1";
        if ($status !== '') {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $sql .= " AND (b.reference_no LIKE ? OR u.username LIKE ? OR s.name LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= " ORDER BY b.created_at DESC";
        $rows = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['bookings' => $rows]);
    }

    private function update_booking_status_with_admin($bookingId, $newStatus, $emailType, $releaseQuantity = false, $incrementQuantity = false)
    {
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $booking = $this->db->table('bookings')->where('id', (int) $bookingId)->limit(1)->get();
        if (!$booking) {
            $this->error('Booking not found.', 404);
            return;
        }

        if ($incrementQuantity && $booking['status'] !== 'Pending') {
            $this->error('Only Pending bookings can be confirmed.', 422);
            return;
        }

        if ($releaseQuantity && !in_array($booking['status'], ['Confirmed', 'Pending'], true)) {
            $this->error('Booking is not in a releasable state.', 422);
            return;
        }

        $this->db->transaction();
        try {
            if ($incrementQuantity) {
                if (!$this->check_availability_or_error((int) $booking['service_id'], $booking['check_in_date'], $booking['check_out_date'])) {
                    $this->db->roll_back();
                    return;
                }
                $this->apply_booking_quantity((int) $booking['service_id'], $booking['check_in_date'], $booking['check_out_date'], 1);
            }

            if ($releaseQuantity && $booking['status'] === 'Confirmed') {
                $this->apply_booking_quantity((int) $booking['service_id'], $booking['check_in_date'], $booking['check_out_date'], -1);
            }

            $update = [
                'status' => $newStatus,
                'approved_by_admin_id' => (int) $admin['id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($newStatus === 'Cancelled' || $newStatus === 'Rejected') {
                $update['cancelled_at'] = date('Y-m-d H:i:s');
            }
            if ($newStatus === 'Completed') {
                $update['completed_at'] = date('Y-m-d H:i:s');
            }

            $this->db->table('bookings')->where('id', (int) $bookingId)->update($update);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            $this->error('Failed to update booking status.', 500);
            return;
        }

        $updated = $this->db->table('bookings')->where('id', (int) $bookingId)->limit(1)->get();
        $this->queue_booking_email($updated, $emailType);
        $this->log_admin_action((int) $admin['id'], 'booking_status_change', 'booking', $bookingId, json_encode([
            'from' => $booking['status'],
            'to' => $newStatus,
        ]));
        $this->respond(['success' => true, 'booking' => $updated]);
    }

    public function admin_confirm_booking($bookingId)
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $this->update_booking_status_with_admin($bookingId, 'Confirmed', 'confirmed', false, true);
    }

    public function admin_reject_booking($bookingId)
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $this->update_booking_status_with_admin($bookingId, 'Cancelled', 'rejected', true, false);
    }

    public function admin_complete_booking($bookingId)
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $this->update_booking_status_with_admin($bookingId, 'Completed', 'completed', false, false);
    }

    public function admin_booking_calendar()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $rows = $this->db->raw(
            "SELECT b.id, b.reference_no, b.check_in_date, b.check_out_date, b.status, s.name AS service_name, u.username
             FROM bookings b
             INNER JOIN services s ON s.id = b.service_id
             INNER JOIN users u ON u.id = b.user_id
             WHERE b.status IN ('Confirmed','Completed')
             ORDER BY b.check_in_date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['events' => $rows]);
    }

    public function admin_services()
    {
        if ($this->request->is_get()) {
            $admin = $this->require_auth('admin');
            if (!$admin) {
                return;
            }
            $rows = $this->db->table('services')->order_by('created_at', 'DESC')->get_all();
            $this->respond(['services' => $rows]);
            return;
        }
        if ($this->request->is_post()) {
            $admin = $this->require_auth('admin');
            if (!$admin) {
                return;
            }
            $data = $this->get_request_data();
            if (!$this->require_csrf($data)) {
                return;
            }
            $name = trim((string) ($data['name'] ?? ''));
            $type = trim((string) ($data['service_type'] ?? ''));
            $description = trim((string) ($data['description'] ?? ''));
            $price = (float) ($data['price'] ?? 0);
            $capacity = (int) ($data['capacity'] ?? 0);
            if ($name === '' || !in_array($type, ['cottage', 'room', 'amenity', 'package'], true) || $price <= 0 || $capacity <= 0) {
                $this->error('Invalid service payload.', 422);
                return;
            }
            $this->db->table('services')->insert([
                'name' => $name,
                'service_type' => $type,
                'description' => $description,
                'price' => $price,
                'capacity' => $capacity,
                'is_active' => 1,
            ]);
            $id = (int) $this->db->last_id();
            $this->log_admin_action((int) $admin['id'], 'create_service', 'service', $id, json_encode($data));
            $this->respond(['success' => true, 'service_id' => $id], 201);
            return;
        }
        if ($this->method_not_allowed('GET, POST, OPTIONS')) {
            return;
        }
    }

    public function admin_update_service($serviceId)
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }

        $service = $this->db->table('services')->where('id', (int) $serviceId)->limit(1)->get();
        if (!$service) {
            $this->error('Service not found.', 404);
            return;
        }

        $update = [];
        foreach (['name', 'service_type', 'description'] as $key) {
            if (isset($data[$key])) {
                $update[$key] = trim((string) $data[$key]);
            }
        }
        if (isset($data['price'])) {
            $update['price'] = (float) $data['price'];
        }
        if (isset($data['capacity'])) {
            $update['capacity'] = (int) $data['capacity'];
        }
        if (isset($data['is_active'])) {
            $update['is_active'] = (int) ((bool) $data['is_active']);
        }
        if ($update === []) {
            $this->error('No service updates provided.', 422);
            return;
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        $this->db->table('services')->where('id', (int) $serviceId)->update($update);
        $this->log_admin_action((int) $admin['id'], 'update_service', 'service', $serviceId, json_encode($update));
        $this->respond(['success' => true]);
    }

    public function admin_deactivate_service($serviceId)
    {
        if ($this->method_not_allowed('DELETE, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }
        $this->db->table('services')->where('id', (int) $serviceId)->update([
            'is_active' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->log_admin_action((int) $admin['id'], 'deactivate_service', 'service', $serviceId, null);
        $this->respond(['success' => true]);
    }

    public function admin_service_availability($serviceId)
    {
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        if ($this->request->is_get()) {
            $rows = $this->db->table('service_availability')
                ->where('service_id', (int) $serviceId)
                ->order_by('available_date', 'ASC')
                ->get_all();
            $this->respond(['availability' => $rows]);
            return;
        }

        if ($this->request->is_put()) {
            $data = $this->get_request_data();
            if (!$this->require_csrf($data)) {
                return;
            }

            $date = (string) ($data['available_date'] ?? '');
            $total = (int) ($data['total_quantity'] ?? 0);
            $isActive = (int) ((bool) ($data['is_active'] ?? true));
            if ($date === '' || $total < 0) {
                $this->error('Invalid availability payload.', 422);
                return;
            }

            $existing = $this->db->table('service_availability')
                ->where('service_id', (int) $serviceId)
                ->where('available_date', $date)
                ->limit(1)
                ->get();

            if ($existing) {
                $this->db->table('service_availability')->where('id', (int) $existing['id'])->update([
                    'total_quantity' => $total,
                    'is_active' => $isActive,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $this->db->table('service_availability')->insert([
                    'service_id' => (int) $serviceId,
                    'available_date' => $date,
                    'total_quantity' => $total,
                    'booked_quantity' => 0,
                    'is_active' => $isActive,
                ]);
            }

            $this->log_admin_action((int) $admin['id'], 'update_service_availability', 'service', $serviceId, json_encode($data));
            $this->respond(['success' => true]);
            return;
        }

        if ($this->method_not_allowed('GET, PUT, OPTIONS')) {
            return;
        }
    }

    public function admin_guests()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $search = trim((string) ($this->request->get('search') ?? ''));
        $sql = "SELECT id, username, email, is_active, created_at FROM users WHERE role = 'guest'";
        $params = [];
        if ($search !== '') {
            $sql .= " AND (username LIKE ? OR email LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= " ORDER BY created_at DESC";
        $rows = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['guests' => $rows]);
    }

    public function admin_guest_status($guestId)
    {
        if ($this->method_not_allowed('PUT, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $data = $this->get_request_data();
        if (!$this->require_csrf($data)) {
            return;
        }
        $isActive = (int) ((bool) ($data['is_active'] ?? true));
        $this->db->table('users')->where('id', (int) $guestId)->where('role', 'guest')->update([
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->log_admin_action((int) $admin['id'], 'update_guest_status', 'user', $guestId, json_encode(['is_active' => $isActive]));
        $this->respond(['success' => true]);
    }

    public function admin_feedback()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $rows = $this->db->raw(
            "SELECT f.id, f.rating, f.comment, f.created_at, b.reference_no, u.username, s.name AS service_name
             FROM feedback f
             INNER JOIN bookings b ON b.id = f.booking_id
             INNER JOIN users u ON u.id = f.user_id
             INNER JOIN services s ON s.id = b.service_id
             ORDER BY f.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['feedback' => $rows]);
    }

    public function admin_reports()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $from = (string) ($this->request->get('from') ?? date('Y-m-01'));
        $to = (string) ($this->request->get('to') ?? date('Y-m-d'));
        $rows = $this->db->raw(
            "SELECT DATE(created_at) AS report_date, COUNT(*) AS booking_count, COALESCE(SUM(total_amount), 0) AS revenue
             FROM bookings
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)
             ORDER BY report_date ASC",
            [$from, $to]
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['reports' => $rows, 'from' => $from, 'to' => $to]);
    }

    public function admin_activity_logs()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }
        $rows = $this->db->raw(
            "SELECT l.*, u.username AS admin_username
             FROM activity_logs l
             INNER JOIN users u ON u.id = l.admin_user_id
             ORDER BY l.created_at DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->respond(['activity_logs' => $rows]);
    }

    public function admin_settings()
    {
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }

        if ($this->request->is_get()) {
            $settings = $this->db->table('system_settings')->get_all();
            $resortInfo = $this->db->table('resort_info')->limit(1)->get();
            $this->respond(['system_settings' => $settings, 'resort_info' => $resortInfo]);
            return;
        }

        if ($this->request->is_put()) {
            $data = $this->get_request_data();
            if (!$this->require_csrf($data)) {
                return;
            }

            if (isset($data['system_settings']) && is_array($data['system_settings'])) {
                foreach ($data['system_settings'] as $key => $value) {
                    $existing = $this->db->table('system_settings')->where('setting_key', (string) $key)->limit(1)->get();
                    if ($existing) {
                        $this->db->table('system_settings')->where('id', (int) $existing['id'])->update([
                            'setting_value' => (string) $value,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    } else {
                        $this->db->table('system_settings')->insert([
                            'setting_key' => (string) $key,
                            'setting_value' => (string) $value,
                        ]);
                    }
                }
            }

            if (isset($data['resort_info']) && is_array($data['resort_info'])) {
                $info = $this->db->table('resort_info')->limit(1)->get();
                if ($info) {
                    $update = [];
                    foreach (['description', 'address', 'phone', 'email', 'google_maps_url'] as $field) {
                        if (isset($data['resort_info'][$field])) {
                            $update[$field] = trim((string) $data['resort_info'][$field]);
                        }
                    }
                    if ($update !== []) {
                        $update['updated_at'] = date('Y-m-d H:i:s');
                        $this->db->table('resort_info')->where('id', (int) $info['id'])->update($update);
                    }
                }
            }

            $this->log_admin_action((int) $admin['id'], 'update_settings', 'system', 'global', null);
            $this->respond(['success' => true]);
            return;
        }

        if ($this->method_not_allowed('GET, PUT, OPTIONS')) {
            return;
        }
    }

    public function admin_backup_export()
    {
        if ($this->method_not_allowed('GET, OPTIONS')) {
            return;
        }
        $admin = $this->require_auth('admin');
        if (!$admin) {
            return;
        }

        $tables = [
            'users',
            'services',
            'service_availability',
            'bookings',
            'feedback',
            'recommendation_requests',
            'recommendation_items',
            'system_settings',
            'resort_info',
            'email_notifications',
            'activity_logs',
        ];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = $this->db->table($table)->get_all();
        }

        $this->log_admin_action((int) $admin['id'], 'backup_export', 'system', 'database', null);
        $this->respond([
            'generated_at' => gmdate('c'),
            'snapshot' => $snapshot,
        ]);
    }
}
?>
