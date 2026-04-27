<?php
/**
 * Plugin Name: Enable MCP Abilities
 * Description: Marks all registered WordPress abilities as public over MCP, exposing them to compatible AI clients (Claude, etc.) via the MCP Adapter.
 * Version: 1.0.0
 * Requires at least: 6.9
 * License: MIT
 *
 * --- THE PUBLIC FLAG MECHANISM ---
 *
 * WordPress's Abilities API + MCP Adapter use a meta flag on each ability:
 *
 *     'meta' => array( 'mcp' => array( 'public' => true ) )
 *
 * Only abilities with this flag set to TRUE are exposed over the MCP endpoint.
 * Without it, abilities are registered in WordPress but invisible to MCP clients.
 *
 * This plugin uses a single filter to mark ALL registered abilities as public.
 * That's intentional for development environments where you trust the abilities
 * being registered. In production, you may want to remove this plugin and instead
 * mark only specific abilities as public on a case-by-case basis.
 *
 * --- SECURITY NOTE ---
 *
 * Each ability still enforces its own permission_callback (e.g. current_user_can()),
 * so the MCP user must be authenticated as a WP user with the correct capabilities.
 * Marking an ability "public" here does NOT mean unauthenticated access.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'wp_ability_meta', function( $meta, $name ) {
    if ( ! isset( $meta['mcp'] ) ) {
        $meta['mcp'] = array();
    }
    $meta['mcp']['public'] = true;
    return $meta;
}, 10, 2 );
