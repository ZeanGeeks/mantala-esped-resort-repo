<?php

class Create_resort_domain_tables {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw("ALTER TABLE users MODIFY COLUMN role ENUM('admin','guest') NOT NULL DEFAULT 'guest'");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS resort_info (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                description TEXT NOT NULL,
                address VARCHAR(255) NOT NULL,
                phone VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                google_maps_url TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $existingResortInfo = $this->_lava->db->table('resort_info')->limit(1)->get();
        if (!$existingResortInfo) {
            $this->_lava->db->table('resort_info')->insert([
                'description' => 'Welcome to ESPED Resort. Experience classic comfort, nature-inspired spaces, and warm hospitality.',
                'address' => 'Sample Address, Philippines',
                'phone' => '+63 900 000 0000',
                'email' => 'stay@espedresort.com',
                'google_maps_url' => 'https://www.google.com/maps?q=14.5995,120.9842&output=embed',
            ]);
        }

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS system_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY setting_key_unique (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS services (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                service_type ENUM('cottage','room','amenity','package') NOT NULL,
                description TEXT NOT NULL,
                price DECIMAL(12,2) NOT NULL,
                capacity INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY service_type_idx (service_type),
                KEY service_active_idx (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS service_availability (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_id INT UNSIGNED NOT NULL,
                available_date DATE NOT NULL,
                total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
                booked_quantity INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY service_date_unique (service_id, available_date),
                CONSTRAINT service_availability_service_fk FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS bookings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                service_id INT UNSIGNED NOT NULL,
                reference_no VARCHAR(50) NOT NULL,
                check_in_date DATE NOT NULL,
                check_out_date DATE NOT NULL,
                guests INT UNSIGNED NOT NULL,
                special_requests TEXT NULL,
                status ENUM('Pending','Confirmed','Cancelled','Completed','Rejected') NOT NULL DEFAULT 'Pending',
                unit_price DECIMAL(12,2) NOT NULL,
                subtotal DECIMAL(12,2) NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL,
                approved_by_admin_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                cancelled_at DATETIME NULL DEFAULT NULL,
                completed_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY reference_no_unique (reference_no),
                KEY booking_status_idx (status),
                KEY booking_user_idx (user_id),
                KEY booking_service_idx (service_id),
                CONSTRAINT booking_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT booking_service_fk FOREIGN KEY (service_id) REFERENCES services(id),
                CONSTRAINT booking_admin_fk FOREIGN KEY (approved_by_admin_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS feedback (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                rating TINYINT UNSIGNED NOT NULL,
                comment TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY booking_feedback_unique (booking_id),
                KEY feedback_user_idx (user_id),
                CONSTRAINT feedback_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings(id),
                CONSTRAINT feedback_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS recommendation_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                maximum_budget DECIMAL(12,2) NOT NULL,
                number_of_guests INT UNSIGNED NOT NULL,
                preferences TEXT NULL,
                response_source ENUM('ai','fallback') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY recommendation_user_idx (user_id),
                CONSTRAINT recommendation_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS recommendation_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id INT UNSIGNED NOT NULL,
                service_id INT UNSIGNED NOT NULL,
                rank_order TINYINT UNSIGNED NOT NULL,
                match_reason TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY recommendation_request_idx (request_id),
                KEY recommendation_service_idx (service_id),
                CONSTRAINT recommendation_item_request_fk FOREIGN KEY (request_id) REFERENCES recommendation_requests(id) ON DELETE CASCADE,
                CONSTRAINT recommendation_item_service_fk FOREIGN KEY (service_id) REFERENCES services(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS email_notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                email_type VARCHAR(100) NOT NULL,
                send_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                sent_at DATETIME NULL DEFAULT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY email_status_idx (send_status),
                KEY email_booking_idx (booking_id),
                KEY email_user_idx (user_id),
                CONSTRAINT email_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
                CONSTRAINT email_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->_lava->db->raw("
            CREATE TABLE IF NOT EXISTS activity_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_user_id INT UNSIGNED NOT NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id VARCHAR(100) NOT NULL,
                details TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY activity_admin_idx (admin_user_id),
                KEY activity_created_idx (created_at),
                CONSTRAINT activity_admin_fk FOREIGN KEY (admin_user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $admin = $this->_lava->db->table('users')->where('role', 'admin')->limit(1)->get();
        if (!$admin) {
            $this->_lava->db->table('users')->insert([
                'username' => 'admin',
                'email' => 'admin@espedresort.com',
                'password' => password_hash('Admin@123456', PASSWORD_DEFAULT),
                'role' => 'admin',
                'is_active' => 1,
            ]);
        }
    }

    public function down()
    {
        $this->_lava->db->raw("DROP TABLE IF EXISTS activity_logs");
        $this->_lava->db->raw("DROP TABLE IF EXISTS email_notifications");
        $this->_lava->db->raw("DROP TABLE IF EXISTS recommendation_items");
        $this->_lava->db->raw("DROP TABLE IF EXISTS recommendation_requests");
        $this->_lava->db->raw("DROP TABLE IF EXISTS feedback");
        $this->_lava->db->raw("DROP TABLE IF EXISTS bookings");
        $this->_lava->db->raw("DROP TABLE IF EXISTS service_availability");
        $this->_lava->db->raw("DROP TABLE IF EXISTS services");
        $this->_lava->db->raw("DROP TABLE IF EXISTS system_settings");
        $this->_lava->db->raw("DROP TABLE IF EXISTS resort_info");
    }
}
