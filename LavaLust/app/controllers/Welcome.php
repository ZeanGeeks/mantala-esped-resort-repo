<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Welcome extends Controller {
	public function index() {
		$this->call->view('welcome_page');
	}

	public function health() {
		$origin = getenv('FRONTEND_URL') ?: 'http://localhost:5173';

		$this->response->with_cors($origin)->send_json([
			'status' => 'ok',
			'service' => 'esped-resort-api',
			'timestamp' => gmdate('c'),
		]);
	}
}
?>