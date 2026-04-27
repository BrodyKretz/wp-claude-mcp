# WordPress MCP Adapter — Setup Guide

Give an AI assistant (like Claude) full editorial access to your WordPress site via the **Model Context Protocol (MCP)**. Once set up, the AI can create, update, and style pages, posts, media, and site settings — all without you touching the WordPress dashboard.

---

## What's Included

```
wordpress-mcp-package/
├── plugins/
│   ├── enable-mcp-abilities/
│   │   └── enable-mcp-abilities.php       # Makes abilities publicly accessible via MCP
│   └── editorial-abilities/
│       └── editorial-abilities.php        # Registers all editorial abilities
└── README.md                              # This file
```

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.9 or higher |
| PHP | 7.4 or higher |
| MCP Adapter Plugin | 0.5.0 or higher |
| Claude.ai account | Any plan |

> **Why WordPress 6.9?** The Abilities API became part of WordPress core in version 6.9. On 6.8 you would need to install a separate Abilities API plugin.

---

## Step-by-Step Setup

### Step 1 — Install the MCP Adapter Plugin

The MCP Adapter is the bridge between WordPress and Claude.

1. Download it from: [github.com/WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter)
2. Upload and activate it in **WordPress Admin → Plugins → Add New → Upload Plugin**

Or install via Composer:
```bash
composer require wordpress/mcp-adapter
```

---

### Step 2 — Install the Two Included Plugins

Upload both plugin folders to your server:

```
wp-content/plugins/enable-mcp-abilities/
wp-content/plugins/editorial-abilities/
```

**Via SSH / SCP:**
```bash
# Copy each plugin folder to your plugins directory
scp -r enable-mcp-abilities/ your-server:/var/www/html/wp-content/plugins/
scp -r editorial-abilities/ your-server:/var/www/html/wp-content/plugins/
```

**Via FTP:** Upload both folders to `wp-content/plugins/`

Then activate both plugins in **WordPress Admin → Plugins**.

---

### Step 3 — Fix File Permissions (if needed)

If WordPress can't write to a plugin file, set correct permissions via SSH:

```bash
# Make writable for editing
sudo chmod 666 /var/www/html/wp-content/plugins/enable-mcp-abilities/enable-mcp-abilities.php

# After saving, lock it back down
sudo chmod 644 /var/www/html/wp-content/plugins/enable-mcp-abilities/enable-mcp-abilities.php
```

---

### Step 4 — Connect Claude to Your WordPress Site

1. Go to **claude.ai** and open **Settings → Connectors** (or the MCP section)
2. Add a new connector with your site's MCP endpoint:
   ```
   https://your-site.com/wp-json/mcp/mcp-adapter-default-server
   ```
3. Authenticate using your WordPress credentials when prompted

---

### Step 5 — Verify the Connection

In a Claude conversation, ask:

> *"Use the WordPress MCP server and call core/get-site-info"*

You should see your site's name, URL, and WordPress version returned. If you see editorial abilities listed when discovering abilities, you're fully set up.

---

## Available Abilities

Once installed, Claude has access to the following abilities:

### Posts
| Ability | Description |
|---|---|
| `editorial/list-posts` | List posts with filters (status, search, pagination) |
| `editorial/get-post` | Get full content of a post by ID |
| `editorial/create-post` | Create a new blog post |
| `editorial/update-post` | Update title, content, status of a post |
| `editorial/delete-post` | Move a post to trash |

### Pages
| Ability | Description |
|---|---|
| `editorial/list-pages` | List all pages |
| `editorial/get-page` | Get full content of a page by ID |
| `editorial/create-page` | Create a new page |
| `editorial/update-page` | Update title, content, status of a page |

### Media
| Ability | Description |
|---|---|
| `editorial/list-media` | Browse the media library |

### Site Settings
| Ability | Description |
|---|---|
| `editorial/get-settings` | Read site title, tagline, timezone, etc. |
| `editorial/update-settings` | Update site title, tagline, timezone, etc. |

### Custom CSS
| Ability | Description |
|---|---|
| `editorial/get-custom-css` | Read current Additional CSS |
| `editorial/set-custom-css` | Replace site-wide CSS (injected into `<head>`) |

### Taxonomy & Navigation
| Ability | Description |
|---|---|
| `editorial/list-categories` | List all post categories |
| `editorial/list-tags` | List all post tags |
| `editorial/list-menus` | List navigation menus |

### Theme & Comments
| Ability | Description |
|---|---|
| `editorial/get-theme-info` | Get active theme details |
| `editorial/list-comments` | List and filter comments |

---

## Security Considerations

- **Authentication**: All abilities use WordPress's built-in `current_user_can()` permission checks. Claude can only do what your authenticated user account can do.
- **Scope**: Access is limited to whichever Claude account is connected. No public exposure is created.
- **CSS**: The `set-custom-css` ability replaces WordPress Additional CSS — it does not allow arbitrary PHP execution.
- **No deletions**: The delete-post ability only moves posts to trash, not permanent deletion.
- **Restricting access**: To limit which abilities are exposed, edit `enable-mcp-abilities.php` and add a conditional on `$ability_name`:

```php
add_filter( 'wp_register_ability_args', function( $args, $ability_name ) {
    $allowed = array(
        'editorial/list-posts',
        'editorial/get-post',
        'editorial/create-post',
        // add only the abilities you want
    );
    if ( in_array( $ability_name, $allowed, true ) ) {
        $args['meta']['mcp']['public'] = true;
    }
    return $args;
}, 10, 2 );
```

---

## Troubleshooting

### Abilities not showing up after activation
Check your WordPress debug log:
```bash
# Enable debug logging in wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

# Then check the log
tail -50 wp-content/debug.log
```

Common errors and fixes:

| Error | Fix |
|---|---|
| `must be registered on wp_abilities_api_init` | Ensure `add_action` uses `wp_abilities_api_init`, not `init` |
| `must contain a category string` | Add `'category' => 'editorial'` to each ability definition |
| `Operation not permitted` on chmod | Use `sudo chmod` |
| Abilities registered but not visible | Check that `enable-mcp-abilities.php` is active and the filter is removing the whitelist |

### CSS showing as text on the page
Do not add `<style>` tags directly to page content — WordPress strips them. Always use the `editorial/set-custom-css` ability instead, which uses the proper WordPress Additional CSS system.

---

## Extending with New Abilities

To add your own abilities, follow this pattern inside `editorial-abilities.php`:

```php
wp_register_ability( 'editorial/my-ability', array(
    'label'       => 'My Ability',
    'description' => 'Does something useful.',
    'category'    => 'editorial',
    'meta'        => array( 'mcp' => array( 'public' => true ) ),
    'input_schema' => array(
        'type'       => 'object',
        'required'   => array( 'my_param' ),
        'properties' => array(
            'my_param' => array( 'type' => 'string', 'description' => 'A required parameter' ),
        ),
    ),
    'permission_callback' => function() {
        return current_user_can( 'edit_posts' ); // set appropriate capability
    },
    'execute_callback' => function( $args ) {
        // your logic here
        return array( 'result' => 'success', 'data' => $args['my_param'] );
    },
) );
```

---

## License

MIT — free to use, modify, and share.
