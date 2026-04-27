# Architecture

This document explains how `wp-claude-mcp` plugs into WordPress and what happens when an MCP client calls an ability.

## The four layers

```
┌──────────────────────────────────────────────────────────┐
│  1. MCP CLIENT  (Claude Desktop, Claude Code, etc.)      │
│     Speaks JSON-RPC over HTTP                            │
└────────────────────────┬─────────────────────────────────┘
                         │  POST /wp-json/mcp/v1/...
                         ▼
┌──────────────────────────────────────────────────────────┐
│  2. MCP ADAPTER  (separate plugin)                       │
│     - Authenticates the request (app password)           │
│     - Validates against the ability's input_schema       │
│     - Filters to only abilities marked mcp.public=true   │
└────────────────────────┬─────────────────────────────────┘
                         │  in-process call
                         ▼
┌──────────────────────────────────────────────────────────┐
│  3. ABILITIES API  (WordPress core, 6.9+)                │
│     - Looks up the ability by name                       │
│     - Calls permission_callback                          │
│     - If allowed, calls execute_callback                 │
└────────────────────────┬─────────────────────────────────┘
                         │  PHP function call
                         ▼
┌──────────────────────────────────────────────────────────┐
│  4. THIS PLUGIN                                          │
│     - execute_callback runs WP core APIs                 │
│       (wp_insert_post, wp_update_custom_css_post, etc.)  │
│     - Returns a structured response                      │
└──────────────────────────────────────────────────────────┘
```

## The "public" mechanism in detail

WordPress's Abilities API stores arbitrary metadata per ability. The MCP Adapter looks for one specific key:

```php
$ability['meta']['mcp']['public'] === true
```

If that's not true, the ability is invisible to MCP — it's still registered, still callable from PHP, just not exposed.

There are two ways to set this flag:

### Per-ability (the explicit way)
```php
wp_register_ability( 'mything/do-thing', array(
    // ...
    'meta' => array(
        'mcp' => array( 'public' => true ),
    ),
) );
```

### Globally (what enable-mcp-abilities does)
```php
add_filter( 'wp_ability_meta', function( $meta, $name ) {
    $meta['mcp']['public'] = true;
    return $meta;
}, 10, 2 );
```

The filter approach is convenient for development but exposes every ability anywhere on the site, including ones added by other plugins. For production, prefer the explicit per-ability approach unless you specifically want a "wide open" stance.

## Why footer JS is a separate ability

WordPress's content sanitizer (`wp_kses_post`) strips:
- `<script>` tags
- Most `<style>` tags
- Most `on*=""` attributes
- `<iframe>` (configurable)

This is correct security behavior. But if an AI client wants to add a "scroll spy" to your nav, it can't just paste a `<script>` tag into a page's content — it gets stripped on save.

The `set-footer-js` ability stores JavaScript in a WordPress option (`mcp_footer_js`) and the plugin echoes it into every page via `wp_footer`. The AI client gets a clean read/write surface for site-wide JS that doesn't bypass the post sanitizer.

## Hook order matters

```
plugins_loaded
  │
  ├─ wp_loaded
  │   ├─ init
  │   │   └─ wp_abilities_api_categories_init   ◄── register categories here
  │   │
  │   └─ wp_abilities_api_init                  ◄── register abilities here
  │
  └─ wp_loaded fully fired
```

Registering on the wrong hook silently fails — abilities don't appear in `wp_get_abilities()` and there's no error log entry. Always use the dedicated hooks.

## What happens on a typical call

1. MCP client sends:
   ```json
   {
     "method": "tools/call",
     "params": {
       "name": "editorial-update-post",
       "arguments": { "id": 142, "title": "New title" }
     }
   }
   ```
2. MCP Adapter validates `arguments` against the ability's `input_schema`.
3. MCP Adapter checks the ability's `meta.mcp.public` flag.
4. MCP Adapter calls `wp_execute_ability( 'editorial/update-post', $args )`.
5. Abilities API runs `permission_callback` — returns false if the connected user can't `edit_posts`.
6. Abilities API runs `execute_callback`, which calls `wp_update_post()`.
7. Response flows back up the stack as JSON.

Every step is auditable. Every permission check is real. The MCP layer is just a transport — it doesn't grant new capabilities, it exposes existing ones.
