<?php
/**
 * Plugin Name: Editorial Abilities for MCP
 * Description: Registers editorial abilities (posts, pages, media, CSS, JS, settings) for AI access via the WordPress MCP Adapter. v1.2.0 adds output schemas, pagination metadata, MCP tool annotations, and actionable error messages following mcp-builder best practices.
 * Version: 1.2.0
 * Requires at least: 6.9
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -----------------------------------------------------------------------------
 * Helpers
 * -------------------------------------------------------------------------- */

/**
 * Build an actionable WP_Error with a "Suggestion: ..." hint appended.
 * Following mcp-builder best practices: errors should guide the agent toward
 * the next thing to try, not just say what went wrong.
 */
function ed_mcp_error( $code, $message, $suggestion = '' ) {
    $full = $message;
    if ( $suggestion ) {
        $full .= ' Suggestion: ' . $suggestion;
    }
    return new WP_Error( $code, $full );
}

/**
 * Build the meta block for an ability with the public flag and MCP annotations.
 *
 * Annotation presets:
 *   read      — read-only, idempotent, non-destructive (list/get)
 *   create    — writes new resource, NOT idempotent (create-post)
 *   update    — modifies existing resource, idempotent (update-post)
 *   delete    — destructive, idempotent (delete-post / trash)
 *   overwrite — replaces a singleton resource, destructive but idempotent (set-css, set-js)
 */
function ed_mcp_meta( $type = 'read' ) {
    $presets = array(
        'read'      => array( 'readOnlyHint' => true,  'destructiveHint' => false, 'idempotentHint' => true,  'openWorldHint' => true ),
        'create'    => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
        'update'    => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true,  'openWorldHint' => true ),
        'delete'    => array( 'readOnlyHint' => false, 'destructiveHint' => true,  'idempotentHint' => true,  'openWorldHint' => true ),
        'overwrite' => array( 'readOnlyHint' => false, 'destructiveHint' => true,  'idempotentHint' => true,  'openWorldHint' => true ),
    );
    return array(
        'mcp' => array(
            'public'      => true,
            'annotations' => isset( $presets[ $type ] ) ? $presets[ $type ] : $presets['read'],
        ),
    );
}

/**
 * Build standard pagination metadata for a list response.
 */
function ed_mcp_pagination( $page, $per_page, $total ) {
    $page     = max( 1, (int) $page );
    $per_page = max( 1, (int) $per_page );
    $total    = (int) $total;
    $has_more = ( $page * $per_page ) < $total;
    return array(
        'total_count' => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'has_more'    => $has_more,
        'next_page'   => $has_more ? $page + 1 : null,
    );
}

function ed_mcp_pagination_schema_props() {
    return array(
        'total_count' => array( 'type' => 'integer', 'description' => 'Total matching items in the dataset.' ),
        'page'        => array( 'type' => 'integer', 'description' => 'Current page number (1-indexed).' ),
        'per_page'    => array( 'type' => 'integer', 'description' => 'Items per page.' ),
        'has_more'    => array( 'type' => 'boolean', 'description' => 'True if more pages remain.' ),
        'next_page'   => array( 'description' => 'Next page number, or null on the last page.' ),
    );
}
function ed_mcp_post_item_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'id'     => array( 'type' => 'integer' ),
            'title'  => array( 'type' => 'string' ),
            'status' => array( 'type' => 'string' ),
            'slug'   => array( 'type' => 'string' ),
            'date'   => array( 'type' => 'string' ),
            'url'    => array( 'type' => 'string', 'format' => 'uri' ),
        ),
    );
}
function ed_mcp_page_item_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'id'     => array( 'type' => 'integer' ),
            'title'  => array( 'type' => 'string' ),
            'status' => array( 'type' => 'string' ),
            'slug'   => array( 'type' => 'string' ),
            'url'    => array( 'type' => 'string', 'format' => 'uri' ),
            'parent' => array( 'type' => 'integer' ),
        ),
    );
}
function ed_mcp_message_id_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'id'      => array( 'type' => 'integer' ),
            'url'     => array( 'type' => 'string', 'format' => 'uri' ),
            'message' => array( 'type' => 'string' ),
        ),
    );
}

/* -----------------------------------------------------------------------------
 * Footer JS injection (wp_kses_post strips <script> from page content,
 * so we route around it by storing JS in an option and emitting it here).
 * -------------------------------------------------------------------------- */
add_action( 'wp_footer', function() {
    $js = get_option( 'mcp_footer_js', '' );
    if ( ! empty( $js ) ) {
        echo '<script>' . $js . '</script>';
    }
} );

/* -----------------------------------------------------------------------------
 * Category registration (must be on wp_abilities_api_categories_init).
 * -------------------------------------------------------------------------- */
add_action( 'wp_abilities_api_categories_init', function() {
    wp_register_ability_category( 'editorial', array(
        'label'       => 'Editorial',
        'description' => 'Abilities for managing posts, pages, media, CSS, JS, and site settings.',
    ) );
} );

/* -----------------------------------------------------------------------------
 * Ability registration (must be on wp_abilities_api_init, NOT init).
 * -------------------------------------------------------------------------- */
add_action( 'wp_abilities_api_init', function() {

    /* ── POSTS ───────────────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/list-posts', array(
        'label'       => 'List Posts',
        'description' => 'Returns a paginated list of posts with optional status and search filters. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'status'   => array( 'type' => 'string', 'default' => 'any', 'description' => "Post status: publish, draft, pending, private, or any." ),
                'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
                'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                'search'   => array( 'type' => 'string', 'description' => 'Free-text search across title and content.' ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array_merge(
                array( 'posts' => array( 'type' => 'array', 'items' => ed_mcp_post_item_schema() ) ),
                ed_mcp_pagination_schema_props()
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $page     = (int)( $args['page'] ?? 1 );
            $per_page = (int)( $args['per_page'] ?? 20 );
            $q = new WP_Query( array(
                'post_type'      => 'post',
                'post_status'    => $args['status'] ?? 'any',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                's'              => $args['search'] ?? '',
            ) );
            $posts = array();
            foreach ( $q->posts as $p ) {
                $posts[] = array(
                    'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                    'slug' => $p->post_name, 'date' => $p->post_date, 'url' => get_permalink( $p->ID ),
                );
            }
            return array_merge(
                array( 'posts' => $posts ),
                ed_mcp_pagination( $page, $per_page, $q->found_posts )
            );
        },
    ) );

    wp_register_ability( 'editorial/get-post', array(
        'label'       => 'Get Post',
        'description' => 'Returns full content of a single post by ID. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'id'      => array( 'type' => 'integer' ),
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'excerpt' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string' ),
                'slug'    => array( 'type' => 'string' ),
                'url'     => array( 'type' => 'string', 'format' => 'uri' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $p = get_post( (int) $args['id'] );
            if ( ! $p || $p->post_type !== 'post' ) {
                return ed_mcp_error( 'not_found', "Post with ID {$args['id']} not found.", 'Use editorial/list-posts to see available post IDs.' );
            }
            return array(
                'id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content,
                'excerpt' => $p->post_excerpt, 'status' => $p->post_status,
                'slug' => $p->post_name, 'url' => get_permalink( $p->ID ),
            );
        },
    ) );

    wp_register_ability( 'editorial/create-post', array(
        'label'       => 'Create Post',
        'description' => 'Creates a new blog post. NOT idempotent — repeated calls create duplicate posts.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'create' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'title' ),
            'properties' => array(
                'title'   => array( 'type' => 'string', 'minLength' => 1 ),
                'content' => array( 'type' => 'string' ),
                'excerpt' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string', 'default' => 'draft', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ) ),
            ),
        ),
        'output_schema' => ed_mcp_message_id_schema(),
        'permission_callback' => function() { return current_user_can( 'publish_posts' ); },
        'execute_callback'    => function( $args ) {
            $id = wp_insert_post( array(
                'post_title'   => sanitize_text_field( $args['title'] ),
                'post_content' => wp_kses_post( $args['content'] ?? '' ),
                'post_excerpt' => sanitize_text_field( $args['excerpt'] ?? '' ),
                'post_status'  => $args['status'] ?? 'draft',
                'post_type'    => 'post',
            ), true );
            if ( is_wp_error( $id ) ) {
                return ed_mcp_error( 'create_failed', $id->get_error_message(), 'Verify the title is non-empty and the status is one of: publish, draft, pending, private, future.' );
            }
            return array( 'id' => $id, 'url' => get_permalink( $id ), 'message' => 'Post created.' );
        },
    ) );

    wp_register_ability( 'editorial/update-post', array(
        'label'       => 'Update Post',
        'description' => 'Updates an existing post by ID. Idempotent — same input, same final state.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'update' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array(
                'id'      => array( 'type' => 'integer', 'minimum' => 1 ),
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'excerpt' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ) ),
            ),
        ),
        'output_schema' => ed_mcp_message_id_schema(),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $id   = (int) $args['id'];
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'post' ) {
                return ed_mcp_error( 'not_found', "Post with ID {$id} not found.", 'Use editorial/list-posts to find the correct ID.' );
            }
            $data = array( 'ID' => $id );
            if ( isset( $args['title'] ) )   $data['post_title']   = sanitize_text_field( $args['title'] );
            if ( isset( $args['content'] ) ) $data['post_content'] = wp_kses_post( $args['content'] );
            if ( isset( $args['excerpt'] ) ) $data['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
            if ( isset( $args['status'] ) )  $data['post_status']  = $args['status'];
            $result = wp_update_post( $data, true );
            if ( is_wp_error( $result ) ) {
                return ed_mcp_error( 'update_failed', $result->get_error_message(), 'Check status value or content sanitization.' );
            }
            return array( 'id' => $result, 'url' => get_permalink( $result ), 'message' => 'Post updated.' );
        },
    ) );

    wp_register_ability( 'editorial/delete-post', array(
        'label'       => 'Delete Post',
        'description' => 'Moves a post to trash. Destructive but idempotent (trashing a trashed post is a no-op).',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'delete' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'id'      => array( 'type' => 'integer' ),
                'message' => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'delete_posts' ); },
        'execute_callback'    => function( $args ) {
            $id = (int) $args['id'];
            if ( ! get_post( $id ) ) {
                return ed_mcp_error( 'not_found', "Post with ID {$id} not found.", 'Use editorial/list-posts to find the correct ID.' );
            }
            $result = wp_trash_post( $id );
            if ( ! $result ) {
                return ed_mcp_error( 'trash_failed', "Could not move post {$id} to trash.", 'The post may already be trashed, or trash may be disabled. Try editorial/list-posts with status=trash.' );
            }
            return array( 'id' => $id, 'message' => 'Post moved to trash.' );
        },
    ) );

    /* ── PAGES ───────────────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/list-pages', array(
        'label'       => 'List Pages',
        'description' => 'Returns a paginated list of pages. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
                'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array_merge(
                array( 'pages' => array( 'type' => 'array', 'items' => ed_mcp_page_item_schema() ) ),
                ed_mcp_pagination_schema_props()
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'execute_callback'    => function( $args ) {
            $page     = (int)( $args['page'] ?? 1 );
            $per_page = (int)( $args['per_page'] ?? 50 );
            $q = new WP_Query( array(
                'post_type'      => 'page',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => $per_page,
                'paged'          => $page,
            ) );
            $pages = array();
            foreach ( $q->posts as $p ) {
                $pages[] = array(
                    'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                    'slug' => $p->post_name, 'url' => get_permalink( $p->ID ), 'parent' => $p->post_parent,
                );
            }
            return array_merge(
                array( 'pages' => $pages ),
                ed_mcp_pagination( $page, $per_page, $q->found_posts )
            );
        },
    ) );

    wp_register_ability( 'editorial/get-page', array(
        'label'       => 'Get Page',
        'description' => 'Returns full content of a single page by ID. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'id'      => array( 'type' => 'integer' ),
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string' ),
                'slug'    => array( 'type' => 'string' ),
                'url'     => array( 'type' => 'string', 'format' => 'uri' ),
                'parent'  => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'execute_callback'    => function( $args ) {
            $p = get_post( (int) $args['id'] );
            if ( ! $p || $p->post_type !== 'page' ) {
                return ed_mcp_error( 'not_found', "Page with ID {$args['id']} not found.", 'Use editorial/list-pages to see available page IDs.' );
            }
            return array(
                'id' => $p->ID, 'title' => $p->post_title, 'content' => $p->post_content,
                'status' => $p->post_status, 'slug' => $p->post_name,
                'url' => get_permalink( $p->ID ), 'parent' => $p->post_parent,
            );
        },
    ) );

    wp_register_ability( 'editorial/create-page', array(
        'label'       => 'Create Page',
        'description' => 'Creates a new WordPress page. NOT idempotent.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'create' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'title' ),
            'properties' => array(
                'title'     => array( 'type' => 'string', 'minLength' => 1 ),
                'content'   => array( 'type' => 'string' ),
                'status'    => array( 'type' => 'string', 'default' => 'draft', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
                'slug'      => array( 'type' => 'string' ),
                'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
            ),
        ),
        'output_schema' => ed_mcp_message_id_schema(),
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
            if ( is_wp_error( $id ) ) {
                return ed_mcp_error( 'create_failed', $id->get_error_message(), 'Verify title is non-empty and parent_id (if set) refers to an existing page.' );
            }
            return array( 'id' => $id, 'url' => get_permalink( $id ), 'message' => 'Page created.' );
        },
    ) );

    wp_register_ability( 'editorial/update-page', array(
        'label'       => 'Update Page',
        'description' => 'Updates an existing page by ID. Idempotent.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'update' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'id' ),
            'properties' => array(
                'id'      => array( 'type' => 'integer', 'minimum' => 1 ),
                'title'   => array( 'type' => 'string' ),
                'content' => array( 'type' => 'string' ),
                'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
                'slug'    => array( 'type' => 'string' ),
            ),
        ),
        'output_schema' => ed_mcp_message_id_schema(),
        'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
        'execute_callback'    => function( $args ) {
            $id   = (int) $args['id'];
            $page = get_post( $id );
            if ( ! $page || $page->post_type !== 'page' ) {
                return ed_mcp_error( 'not_found', "Page with ID {$id} not found.", 'Use editorial/list-pages to find the correct ID.' );
            }
            $data = array( 'ID' => $id );
            if ( isset( $args['title'] ) )   $data['post_title']   = sanitize_text_field( $args['title'] );
            if ( isset( $args['content'] ) ) $data['post_content'] = wp_kses_post( $args['content'] );
            if ( isset( $args['status'] ) )  $data['post_status']  = $args['status'];
            if ( isset( $args['slug'] ) )    $data['post_name']    = sanitize_title( $args['slug'] );
            $result = wp_update_post( $data, true );
            if ( is_wp_error( $result ) ) {
                return ed_mcp_error( 'update_failed', $result->get_error_message(), 'Check status value or content sanitization.' );
            }
            return array( 'id' => $result, 'url' => get_permalink( $result ), 'message' => 'Page updated.' );
        },
    ) );

    /* ── MEDIA ───────────────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/list-media', array(
        'label'       => 'List Media',
        'description' => 'Returns a paginated list of media library items, optionally filtered by MIME type or search. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'per_page'   => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
                'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                'media_type' => array( 'type' => 'string', 'description' => "Filter by MIME prefix, e.g. 'image' or 'image/png'." ),
                'search'     => array( 'type' => 'string', 'description' => 'Free-text search.' ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array_merge(
                array( 'media' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id'       => array( 'type' => 'integer' ),
                            'title'    => array( 'type' => 'string' ),
                            'url'      => array( 'type' => 'string', 'format' => 'uri' ),
                            'type'     => array( 'type' => 'string', 'description' => 'MIME type.' ),
                            'filename' => array( 'type' => 'string' ),
                            'date'     => array( 'type' => 'string' ),
                        ),
                    ),
                ) ),
                ed_mcp_pagination_schema_props()
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'upload_files' ); },
        'execute_callback'    => function( $args ) {
            $page     = (int)( $args['page'] ?? 1 );
            $per_page = (int)( $args['per_page'] ?? 20 );
            $query_args = array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                's'              => $args['search'] ?? '',
            );
            if ( isset( $args['media_type'] ) ) $query_args['post_mime_type'] = $args['media_type'];
            $q = new WP_Query( $query_args );
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
            return array_merge(
                array( 'media' => $items ),
                ed_mcp_pagination( $page, $per_page, $q->found_posts )
            );
        },
    ) );

    /* ── SITE SETTINGS ───────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/get-settings', array(
        'label'       => 'Get Site Settings',
        'description' => 'Returns common WordPress site settings. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'blogname'            => array( 'type' => 'string' ),
                'blogdescription'     => array( 'type' => 'string' ),
                'siteurl'             => array( 'type' => 'string', 'format' => 'uri' ),
                'admin_email'         => array( 'type' => 'string', 'format' => 'email' ),
                'posts_per_page'      => array( 'type' => 'integer' ),
                'timezone_string'     => array( 'type' => 'string' ),
                'permalink_structure' => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'execute_callback'    => function( $args ) {
            return array(
                'blogname'            => get_option( 'blogname' ),
                'blogdescription'     => get_option( 'blogdescription' ),
                'siteurl'             => get_option( 'siteurl' ),
                'admin_email'         => get_option( 'admin_email' ),
                'posts_per_page'      => (int) get_option( 'posts_per_page' ),
                'timezone_string'     => get_option( 'timezone_string' ),
                'permalink_structure' => get_option( 'permalink_structure' ),
            );
        },
    ) );

    wp_register_ability( 'editorial/update-settings', array(
        'label'       => 'Update Site Settings',
        'description' => 'Updates common WordPress site settings. Idempotent.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'update' ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'blogname'        => array( 'type' => 'string' ),
                'blogdescription' => array( 'type' => 'string' ),
                'posts_per_page'  => array( 'type' => 'integer', 'minimum' => 1 ),
                'timezone_string' => array( 'type' => 'string' ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'message' => array( 'type' => 'string' ),
                'updated' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
            if ( empty( $updated ) ) {
                return ed_mcp_error( 'no_changes', 'No settings were provided to update.', 'Pass at least one of: blogname, blogdescription, posts_per_page, timezone_string.' );
            }
            return array( 'message' => 'Settings updated.', 'updated' => $updated );
        },
    ) );

    /* ── CUSTOM CSS ──────────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/get-custom-css', array(
        'label'       => 'Get Custom CSS',
        'description' => "Returns the active theme's Customizer CSS. Read-only.",
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array( 'css' => array( 'type' => 'string' ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_css' ); },
        'execute_callback'    => function( $args ) {
            $post = wp_get_custom_css_post();
            return array( 'css' => $post ? $post->post_content : '' );
        },
    ) );

    wp_register_ability( 'editorial/set-custom-css', array(
        'label'       => 'Set Custom CSS',
        'description' => "Replaces the active theme's Customizer CSS. Destructive (overwrites previous value) but idempotent.",
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'overwrite' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'css' ),
            'properties' => array(
                'css' => array( 'type' => 'string', 'description' => 'Full CSS content. Replaces previous value entirely — fetch with editorial/get-custom-css first if you want to append.' ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'message' => array( 'type' => 'string' ),
                'id'      => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_css' ); },
        'execute_callback'    => function( $args ) {
            $result = wp_update_custom_css_post( $args['css'] );
            if ( is_wp_error( $result ) ) {
                return ed_mcp_error( 'css_save_failed', $result->get_error_message(), 'Verify the CSS is syntactically valid; WordPress runs basic validation before saving.' );
            }
            return array( 'message' => 'Custom CSS updated successfully.', 'id' => $result->ID );
        },
    ) );

    /* ── FOOTER JAVASCRIPT ───────────────────────────────────────────────── */
    /* WordPress strips <script> tags from page content via wp_kses_post.    */
    /* This pair routes around it: JS lives in an option and is injected    */
    /* via the wp_footer hook at the top of this file.                      */

    wp_register_ability( 'editorial/get-footer-js', array(
        'label'       => 'Get Footer JavaScript',
        'description' => 'Returns the current site-wide footer JavaScript. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array( 'js' => array( 'type' => 'string' ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'execute_callback'    => function( $args ) {
            return array( 'js' => get_option( 'mcp_footer_js', '' ) );
        },
    ) );

    wp_register_ability( 'editorial/set-footer-js', array(
        'label'       => 'Set Footer JavaScript',
        'description' => 'Replaces the site-wide footer JavaScript. Use this instead of inline <script> tags in page content (which WordPress strips). Destructive but idempotent.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'overwrite' ),
        'input_schema' => array(
            'type' => 'object', 'required' => array( 'js' ),
            'properties' => array(
                'js' => array( 'type' => 'string', 'description' => 'JavaScript to inject into wp_footer on every page. Replaces previous value entirely.' ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array( 'message' => array( 'type' => 'string' ) ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'execute_callback'    => function( $args ) {
            update_option( 'mcp_footer_js', $args['js'] );
            return array( 'message' => 'Footer JS updated successfully.' );
        },
    ) );

    /* ── TAXONOMY ────────────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/list-categories', array(
        'label'       => 'List Categories',
        'description' => 'Returns all post categories. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'categories'  => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id'    => array( 'type' => 'integer' ),
                            'name'  => array( 'type' => 'string' ),
                            'slug'  => array( 'type' => 'string' ),
                            'count' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'total_count' => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $cats   = get_categories( array( 'hide_empty' => false ) );
            $result = array();
            foreach ( $cats as $c ) {
                $result[] = array( 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => (int) $c->count );
            }
            return array( 'categories' => $result, 'total_count' => count( $result ) );
        },
    ) );

    wp_register_ability( 'editorial/list-tags', array(
        'label'       => 'List Tags',
        'description' => 'Returns all post tags. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'tags'        => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id'    => array( 'type' => 'integer' ),
                            'name'  => array( 'type' => 'string' ),
                            'slug'  => array( 'type' => 'string' ),
                            'count' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'total_count' => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
        'execute_callback'    => function( $args ) {
            $tags   = get_tags( array( 'hide_empty' => false ) );
            $result = array();
            foreach ( $tags as $t ) {
                $result[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count );
            }
            return array( 'tags' => $result, 'total_count' => count( $result ) );
        },
    ) );

    /* ── MENUS & THEME ───────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/list-menus', array(
        'label'       => 'List Menus',
        'description' => 'Returns all registered navigation menus. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'menus'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id'    => array( 'type' => 'integer' ),
                            'name'  => array( 'type' => 'string' ),
                            'slug'  => array( 'type' => 'string' ),
                            'count' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'total_count' => array( 'type' => 'integer' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'edit_theme_options' ); },
        'execute_callback'    => function( $args ) {
            $menus  = wp_get_nav_menus();
            $result = array();
            foreach ( $menus as $m ) {
                $result[] = array( 'id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'count' => (int) $m->count );
            }
            return array( 'menus' => $result, 'total_count' => count( $result ) );
        },
    ) );

    wp_register_ability( 'editorial/get-theme-info', array(
        'label'       => 'Get Theme Info',
        'description' => 'Returns information about the active theme. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array( 'type' => 'object', 'properties' => array() ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array(
                'name'        => array( 'type' => 'string' ),
                'version'     => array( 'type' => 'string' ),
                'author'      => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'template'    => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'switch_themes' ); },
        'execute_callback'    => function( $args ) {
            $t = wp_get_theme();
            return array(
                'name'        => $t->get( 'Name' ),
                'version'     => $t->get( 'Version' ),
                'author'      => wp_strip_all_tags( $t->get( 'Author' ) ),
                'description' => $t->get( 'Description' ),
                'template'    => $t->get_template(),
            );
        },
    ) );

    /* ── COMMENTS ────────────────────────────────────────────────────────── */

    wp_register_ability( 'editorial/list-comments', array(
        'label'       => 'List Comments',
        'description' => 'Returns a paginated list of comments, optionally filtered by status or post. Read-only.',
        'category'    => 'editorial',
        'meta'        => ed_mcp_meta( 'read' ),
        'input_schema' => array(
            'type' => 'object',
            'properties' => array(
                'status'   => array( 'type' => 'string', 'default' => 'any', 'description' => "Comment status: approve, hold, spam, trash, or any." ),
                'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
                'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                'post_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
            ),
        ),
        'output_schema' => array(
            'type' => 'object',
            'properties' => array_merge(
                array( 'comments' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'id'      => array( 'type' => 'integer' ),
                            'post_id' => array( 'type' => 'integer' ),
                            'author'  => array( 'type' => 'string' ),
                            'content' => array( 'type' => 'string' ),
                            'status'  => array( 'type' => 'string' ),
                            'date'    => array( 'type' => 'string' ),
                        ),
                    ),
                ) ),
                ed_mcp_pagination_schema_props()
            ),
        ),
        'permission_callback' => function() { return current_user_can( 'moderate_comments' ); },
        'execute_callback'    => function( $args ) {
            $page     = (int)( $args['page'] ?? 1 );
            $per_page = (int)( $args['per_page'] ?? 20 );
            $qargs = array(
                'status' => $args['status'] ?? 'any',
                'number' => $per_page,
                'offset' => ( $page - 1 ) * $per_page,
            );
            if ( isset( $args['post_id'] ) ) $qargs['post_id'] = (int) $args['post_id'];
            $comments = get_comments( $qargs );
            // Total (without limit/offset)
            $count_args = $qargs;
            unset( $count_args['number'], $count_args['offset'] );
            $count_args['count'] = true;
            $total = (int) get_comments( $count_args );
            $result = array();
            foreach ( $comments as $c ) {
                $result[] = array(
                    'id' => (int) $c->comment_ID, 'post_id' => (int) $c->comment_post_ID,
                    'author' => $c->comment_author, 'content' => $c->comment_content,
                    'status' => $c->comment_approved, 'date' => $c->comment_date,
                );
            }
            return array_merge(
                array( 'comments' => $result ),
                ed_mcp_pagination( $page, $per_page, $total )
            );
        },
    ) );

} );
