<?php

class ClientPortal_Permissions_Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        do_action('rest_api_init');
    }

    public function test_projects_endpoint_requires_portal_access()
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('GET', '/client-portal/v1/projects');
        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_document_creation_requires_nonce()
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('POST', '/client-portal/v1/documents');
        $request->set_param('title', 'Example Document');

        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_guided_tour_update_requires_nonce()
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('POST', '/client-portal/v1/tour/progress');
        $request->set_body(json_encode(['action' => 'complete', 'step' => 'portal-settings']));
        $request->set_header('Content-Type', 'application/json');

        $response = rest_do_request($request);
        $this->assertSame(403, $response->get_status());

        $request = new WP_REST_Request('POST', '/client-portal/v1/tour/progress');
        $request->set_body(json_encode(['action' => 'complete', 'step' => 'portal-settings']));
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-Portal-Nonce', wp_create_nonce('vhona_cp_tour_update'));

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
    }
}
