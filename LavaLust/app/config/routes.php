<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');
$router->get('/api/health', 'Welcome::health');

$router->get('/api/v1/health', 'Api::health');
$router->get('/api/v1/csrf', 'Api::csrf');

$router->post('/api/v1/auth/register', 'Api::register');
$router->post('/api/v1/auth/login', 'Api::login');
$router->post('/api/v1/auth/logout', 'Api::logout');
$router->get('/api/v1/auth/me', 'Api::me');
$router->put('/api/v1/auth/account', 'Api::update_account');
$router->put('/api/v1/auth/password', 'Api::update_password');

$router->get('/api/v1/home', 'Api::home');
$router->get('/api/v1/resort-info', 'Api::resort_info');
$router->get('/api/v1/services', 'Api::services');
$router->post('/api/v1/recommendation', 'Api::recommendation');

$router->post('/api/v1/bookings', 'Api::create_booking');
$router->get('/api/v1/bookings/my', 'Api::my_bookings');
$router->delete('/api/v1/bookings/{id}/cancel', 'Api::cancel_my_booking');
$router->post('/api/v1/bookings/{id}/feedback', 'Api::submit_feedback');

$router->get('/api/v1/admin/dashboard', 'Api::admin_dashboard');
$router->get('/api/v1/admin/bookings', 'Api::admin_bookings');
$router->put('/api/v1/admin/bookings/{id}/confirm', 'Api::admin_confirm_booking');
$router->put('/api/v1/admin/bookings/{id}/reject', 'Api::admin_reject_booking');
$router->put('/api/v1/admin/bookings/{id}/complete', 'Api::admin_complete_booking');
$router->get('/api/v1/admin/bookings/calendar', 'Api::admin_booking_calendar');

$router->get('/api/v1/admin/services', 'Api::admin_services');
$router->post('/api/v1/admin/services', 'Api::admin_services');
$router->put('/api/v1/admin/services/{id}', 'Api::admin_update_service');
$router->delete('/api/v1/admin/services/{id}', 'Api::admin_deactivate_service');
$router->get('/api/v1/admin/services/{id}/availability', 'Api::admin_service_availability');
$router->put('/api/v1/admin/services/{id}/availability', 'Api::admin_service_availability');

$router->get('/api/v1/admin/guests', 'Api::admin_guests');
$router->put('/api/v1/admin/guests/{id}/status', 'Api::admin_guest_status');
$router->get('/api/v1/admin/feedback', 'Api::admin_feedback');
$router->get('/api/v1/admin/reports', 'Api::admin_reports');
$router->get('/api/v1/admin/activity-logs', 'Api::admin_activity_logs');
$router->get('/api/v1/admin/settings', 'Api::admin_settings');
$router->put('/api/v1/admin/settings', 'Api::admin_settings');
$router->get('/api/v1/admin/backup/export', 'Api::admin_backup_export');