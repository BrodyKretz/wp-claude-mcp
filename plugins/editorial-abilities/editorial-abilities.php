<?php
/**
 * Plugin Name: Editorial Abilities for MCP
 * Description: Registers editorial abilities (posts, pages, media, CSS, JS, settings) for AI access via MCP Adapter.
 * Version: 1.1.0
 * Requires at least: 6.9
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Inject stored footer JS into every page ──────────────────────────────────
add_action( 'wp_footer', function() {
    $js = get_option( 'mcp_footer_js', '' );
    if ( ! empty( $js ) ) {
        echo '<script>' . $js . '</script>';
    }
} );

// ─── Register the "editorial" ability category ────────────────────────────────
add_action( 'wp_abilities_api_categories_init', function() {
    wp_register_ability_category( 'editorial', array(
        'label'       => 'Editorial',
        'description' => 'Abilities for managing posts, pages, media, CSS, JS, and site settings.',
    ) );
} );

// ─── Register all editorial abilities ────────────────────────────────────────
add_action( 'wp_abilities_api_init', function() {

    // ── POSTS ──────────────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/list-posts', array(
        'label'       => 'List Posts',
        'description' => 'Returns a list of posts with optional filters.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'status'   => array( 'type' => 'string', 'default' => 'any' ),
                'per_page' => array( 'type' => 'integer', 'default' => 20 ),
                'page'     => array( 'type' => 'integer', 'default' => 1 ),
                'search'   => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $q = new WP_Query( array(
                'post_type'      => 'post',
                'post_status'    => $args['status'] ?? 'any',
                'posts_per_page' => (int)( $args['per_page'] ?? 20 ),
                'paged'          => (int)( $args['page'] ?? 1 ),
                's'              => $args['search'] ?? '',
            ) );
            $posts = array();
            foreach ( $q->posts as $p ) {
                $posts[] = array(
                    'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                    'slug' => $p->post_name, 'date' => $p->post_date, 'url' => get_permalink( $p->ID ),
                );
            }
            return array( 'posts' => $posts, 'total' => $q->found_posts );
        },
    ) );

    wp_register_ability( 'editorial/get-post', array(
        'label'       => 'Get Post',
        'description' => 'Returns full content of a single post by ID.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array( 'id' => array( 'type' => 'integer' ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $p = get_post( (int) $args['id'] );
            if ( ! $p ) return new WP_Error( 'not_found', 'Post not found' );
            return array(
                'id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content,
                'excerpt' => $p->post_excerpt, 'status' => $p->post_status,
                'slug' => $p->post_name, 'url' => get_permalink( $p->ID ),
            );
        },
    ) );

    wp_register_ability( 'editorial/create-post', array(
        'label'       => 'Create Post',
        'description' => 'Creates a new blog post.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'title' ),
            'properties' => array(
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'excerpt' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string', 'default' => 'draft' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'publish_posts' ); },
        'execute_callback'    => function( $args ) {
            $id = wp_insert_post( array(
                'post_title'   => sanitize_text_field( $args['title'] ),
                'post_content' => wp_kses_post( $args['content'] ?? '' ),
                'post_excerpt' => sanitize_text_field( $args['excerpt'] ?? '' ),
                'post_status'  => $args['status'] ?? 'draft',
                'post_type'    => 'post',
            ), true );
            if ( is_wp_error( $id ) ) return $id;
            return array( 'id' => $id, 'url' => get_permalink( $id ), 'message' => 'Post created.' );
        },
    ) );

    wp_register_ability( 'editorial/update-post', array(
        'label'       => 'Update Post',
        'description' => 'Updates an existing post by ID.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array(
                'id'      => array( 'type' => 'integer' ),
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'excerpt' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $data = array( 'ID' => (int) $args['id'] );
            if ( isset( $args['title'] ) )   $data['post_title']   = sanitize_text_field( $args['title'] );
            if ( isset( $args['content'] ) ) $data['post_content'] = wp_kses_post( $args['content'] );
            if ( isset( $args['excerpt'] ) ) $data['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
            if ( isset( $args['status'] ) )  $data['post_status']  = $args['status'];
            $result = wp_update_post( $data, true );
            if ( is_wp_error( $result ) ) return $result;
            return array( 'id' => $result, 'url' => get_permalink( $result ), 'message' => 'Post updated.' );
        },
    ) );

    wp_register_ability( 'editorial/delete-post', array(
        'label'       => 'Delete Post',
        'description' => 'Moves a post to trash.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array( 'id' => array( 'type' => 'integer' ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'delete_posts' ); },
        'execute_callback'    => function( $args ) {
            $result = wp_trash_post( (int) $args['id'] );
            if ( ! $result ) return new WP_Error( 'failed', 'Could not trash post.' );
            return array( 'message' => 'Post moved to trash.', 'id' => (int) $args['id'] );
        },
    ) );

    // ── PAGES ─────────────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/list-pages', array(
        'label'       => 'List Pages',
        'description' => 'Returns a list of pages.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array( 'per_page' => array( 'type' => 'integer', 'default' => 50 ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'execute_callback'    => function( $args ) {
            $pages = get_pages( array(
                'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
                'number'      => (int)( $args['per_page'] ?? 50 ),
            ) );
            $result = array();
            foreach ( $pages as $page ) {
                $result[] = array(
                    'id' => $page->ID, 'title' => $page->post_title, 'status' => $page->post_status,
                    'slug' => $page->post_name, 'url' => get_permalink( $page->ID ), 'parent' => $page->post_parent,
                );
            }
            return array( 'pages' => $result );
        },
    ) );

    wp_register_ability( 'editorial/get-page', array(
        'label'       => 'Get Page',
        'description' => 'Returns full content of a single page by ID.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array( 'id' => array( 'type' => 'integer' ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'execute_callback'    => function( $args ) {
            $p = get_post( (int) $args['id'] );
            if ( ! $p || $p->post_type !== 'page' ) return new WP_Error( 'not_found', 'Page not found' );
            return array(
                'id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content,
                'status' => $p->post_status, 'slug' => $p->post_name,
                'url' => get_permalink( $p->ID ), 'parent' => $p->post_parent,
            );
        },
    ) );

    wp_register_ability( 'editorial/create-page', array(
        'label'       => 'Create Page',
        'description' => 'Creates a new WordPress page.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'title' ),
            'properties' => array(
                'title'     => array( 'type' => 'string' ),
                'content'   => array( 'type' => 'string' ),
                'status'    => array( 'type' => 'string', 'default' => 'draft' ),
                'slug'      => array( 'type' => 'string' ),
                'parent_id' => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'publish_pages' ); },
        'execute_callback'    => function( $args ) {
            $id = wp_insert_post( array(
                'post_title'   => sanitize_text_field( $args['title'] ),
                'post_content' => wp_kses_post( $args['content'] ?? '' ),
                'post_status'  => $args['status'] ?? 'draft',
                'post_name'    => isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '',
                'post_parent'  => (int)( $args['parent_id'] ?? 0 ),
                'post_type'    => 'page',
            ), true );
            if ( is_wp_error( $id ) ) return $id;
            return array( 'id' => $id, 'url' => get_permalink( $id ), 'message' => 'Page created.' );
        },
    ) );

    wp_register_ability( 'editorial/update-page', array(
        'label'       => 'Update Page',
        'description' => 'Updates an existing page by ID.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array(
                'id'      => array( 'type' => 'integer' ),
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string' ),
                'slug'    => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'execute_callback'    => function( $args ) {
            $data = array( 'ID' => (int) $args['id'] );
            if ( isset( $args['title'] ) )   $data['post_title']   = sanitize_text_field( $args['title'] );
            if ( isset( $args['content'] ) ) $data['post_content'] = wp_kses_post( $args['content'] );
            if ( isset( $args['status'] ) )  $data['post_status']  = $args['status'];
            if ( isset( $args['slug'] ) )    $data['post_name']    = sanitize_title( $args['slug'] );
            $result = wp_update_post( $data, true );
            if ( is_wp_error( $result ) ) return $result;
            return array( 'id' => $result, 'url' => get_permalink( $result ), 'message' => 'Page updated.' );
        },
    ) );

    // ── MEDIA ─────────────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/list-media', array(
        'label'       => 'List Media',
        'description' => 'Returns media library items.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'per_page'   => array( 'type' => 'integer', 'default' => 20 ),
                'media_type' => array( 'type' => 'string' ),
                'search'     => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'upload_files' ); },
        'execute_callback'    => function( $args ) {
            $query_args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => (int)( $args['per_page'] ?? 20 ),
                's'              => $args['search'] ?? '',
            );
            if ( isset( $args['media_type'] ) ) $query_args['post_mime_type'] = $args['media_type'];
            $q     = new WP_Query( $query_args );
            $items = array();
            foreach ( $q->posts as $item ) {
                $items[] = array(
                    'id' => $item->ID, 'title' => $item->post_title,
                    'url' => wp_get_attachment_url( $item->ID ),
                    'type' => $item->post_mime_type,
                    'filename' => basename( get_attached_file( $item->ID ) ),
                    'date' => $item->post_date,
                );
            }
            return array( 'media' => $items, 'total' => $q->found_posts );
        },
    ) );

    // ── SITE SETTINGS ─────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/get-settings', array(
        'label'       => 'Get Site Settings',
        'description' => 'Returns common WordPress site settings.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'execute_callback'    => function( $args ) {
            return array(
                'blogname'            => get_option( 'blogname' ),
                'blogdescription'     => get_option( 'blogdescription' ),
                'siteurl'             => get_option( 'siteurl' ),
                'admin_email'         => get_option( 'admin_email' ),
                'posts_per_page'      => get_option( 'posts_per_page' ),
                'timezone_string'     => get_option( 'timezone_string' ),
                'permalink_structure' => get_option( 'permalink_structure' ),
            );
        },
    ) );

    wp_register_ability( 'editorial/update-settings', array(
        'label'       => 'Update Site Settings',
        'description' => 'Updates WordPress site settings.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'blogname'        => array( 'type' => 'string' ),
                'blogdescription' => array( 'type' => 'string' ),
                'posts_per_page'  => array( 'type' => 'integer' ),
                'timezone_string' => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'execute_callback'    => function( $args ) {
            $allowed = array( 'blogname', 'blogdescription', 'posts_per_page', 'timezone_string' );
            $updated = array();
            foreach ( $allowed as $key ) {
                if ( isset( $args[ $key ] ) ) {
                    update_option( $key, $args[ $key ] );
                    $updated[] = $key;
                }
            }
            return array( 'message' => 'Settings updated.', 'updated' => $updated );
        },
    ) );

    // ── CUSTOM CSS ────────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/get-custom-css', array(
        'label'       => 'Get Custom CSS',
        'description' => 'Returns the current WordPress Additional CSS.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'edit_css' ); },
        'execute_callback'    => function( $args ) {
            $post = wp_get_custom_css_post();
            return array( 'css' => $post ? $post->post_content : '' );
        },
    ) );

    wp_register_ability( 'editorial/set-custom-css', array(
        'label'       => 'Set Custom CSS',
        'description' => 'Replaces the WordPress Additional CSS (injected into <head>).',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'css' ),
            'properties' => array(
                'css' => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_css' ); },
        'execute_callback'    => function( $args ) {
            $result = wp_update_custom_css_post( $args['css'] );
            if ( is_wp_error( $result ) ) return $result;
            return array( 'message' => 'Custom CSS updated successfully.', 'id' => $result->ID );
        },
    ) );

    // ── FOOTER JAVASCRIPT ─────────────────────────────────────────────────────
    // WordPress strips <script> tags from page content, so JS must be injected
    // via wp_footer. This ability stores JS in the database and the wp_footer
    // hook at the top of this file outputs it on every page load.

    wp_register_ability( 'editorial/get-footer-js', array(
        'label'       => 'Get Footer JavaScript',
        'description' => 'Returns the current site-wide footer JavaScript.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'execute_callback'    => function( $args ) {
            return array( 'js' => get_option( 'mcp_footer_js', '' ) );
        },
    ) );

    wp_register_ability( 'editorial/set-footer-js', array(
        'label'       => 'Set Footer JavaScript',
        'description' => 'Stores JavaScript that is injected into the site footer on every page. Use this instead of inline <script> tags in page content, which WordPress strips.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'js' ),
            'properties' => array(
                'js' => array( 'type' => 'string', 'description' => 'JavaScript to inject into wp_footer on every page' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'execute_callback'    => function( $args ) {
            update_option( 'mcp_footer_js', $args['js'] );
            return array( 'message' => 'Footer JS updated successfully.' );
        },
    ) );

    // ── TAXONOMY ──────────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/list-categories', array(
        'label'       => 'List Categories',
        'description' => 'Returns all post categories.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $cats   = get_categories( array( 'hide_empty' => false ) );
            $result = array();
            foreach ( $cats as $c ) {
                $result[] = array( 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count );
            }
            return array( 'categories' => $result );
        },
    ) );

    wp_register_ability( 'editorial/list-tags', array(
        'label'       => 'List Tags',
        'description' => 'Returns all post tags.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $tags   = get_tags( array( 'hide_empty' => false ) );
            $result = array();
            foreach ( $tags as $t ) {
                $result[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count );
            }
            return array( 'tags' => $result );
        },
    ) );

    // ── MENUS & THEME ─────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/list-menus', array(
        'label'       => 'List Menus',
        'description' => 'Returns all registered navigation menus.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'execute_callback'    => function( $args ) {
            $menus  = wp_get_nav_menus();
            $result = array();
            foreach ( $menus as $m ) {
                $result[] = array( 'id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'count' => $m->count );
            }
            return array( 'menus' => $result );
        },
    ) );

    wp_register_ability( 'editorial/get-theme-info', array(
        'label'       => 'Get Theme Info',
        'description' => 'Returns information about the active theme.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'permission_callback' => function() { return current_user_can( 'switch_themes' ); },
        'execute_callback'    => function( $args ) {
            $t = wp_get_theme();
            return array(
                'name'        => $t->get( 'Name' ),
                'version'     => $t->get( 'Version' ),
                'author'      => $t->get( 'Author' ),
                'description' => $t->get( 'Description' ),
                'template'    => $t->get_template(),
            );
        },
    ) );

    // ── COMMENTS ─────────────────────────────────────────────────────────────

    wp_register_ability( 'editorial/list-comments', array(
        'label'       => 'List Comments',
        'description' => 'Returns a list of comments.',
        'category'    => 'editorial',
        'meta'        => array( 'mcp' => array( 'public' => true ) ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'status'   => array( 'type' => 'string', 'default' => 'any' ),
                'per_page' => array( 'type' => 'integer', 'default' => 20 ),
                'post_id'  => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'moderate_comments' ); },
        'execute_callback'    => function( $args ) {
            $qargs = array(
                'status' => $args['status'] ?? 'any',
                'number' => (int)( $args['per_page'] ?? 20 ),
            );
            if ( isset( $args['post_id'] ) ) $qargs['post_id'] = (int) $args['post_id'];
            $comments = get_comments( $qargs );
            $result   = array();
            foreach ( $comments as $c ) {
                $result[] = array(
                    'id' => $c->comment_ID, 'post_id' => $c->comment_post_ID,
                    'author' => $c->comment_author, 'content' => $c->comment_content,
                    'status' => $c->comment_approved, 'date' => $c->comment_date,
                );
            }
            return array( 'comments' => $result );
        },
    ) );

} );
