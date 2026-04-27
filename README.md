# wp-claude-mcp

Give Claude (or any MCP-compatible AI client) full editorial access to a self-hosted WordPress site. Two small plugins, no SaaS, no third-party services in the data path.

Once installed, an AI agent can list and edit posts and pages, manage the media library, update site settings and Customizer CSS, inject footer JavaScript, and read/write everything an editor would normally do through `/wp-admin` — all over the [Model Context Protocol](https://modelcontextprotocol.io/).

```
┌─────────────┐      MCP        ┌──────────────────────┐    Abilities API     ┌──────────────┐
│ Claude (or  │ ──────────────▶ │ WordPress MCP        │ ────────────────────▶ │ This plugin's│
│ any MCP     │  (HTTP, JSON)   │ Adapter              │  (in-process calls)   │ 20+ abilities│
│ client)     │ ◀────────────── │ /wp-json/mcp/v1/...  │ ◀──────────────────── │              │
└─────────────┘                 └──────────────────────┘                       └──────────────┘
```

---

## What's new in v1.2.0

This release applies the [mcp-builder skill](https://www.anthropic.com/) best practices across all 20 abilities:

- **Output schemas** — every ability now declares an `output_schema` so MCP clients know the exact shape and types of returned data, enabling structured-content rendering and reliable downstream chaining.
- **Real pagination metadata** — every list operation returns `total_count`, `page`, `per_page`, `has_more`, and `next_page` so clients can iterate through large datasets without guessing.
- **MCP tool annotations** — `readOnlyHint`, `destructiveHint`, `idempotentHint`, and `openWorldHint` are set per ability via `meta.mcp.annotations`. List operations are read-only; create operations are non-idempotent; delete and overwrite operations are flagged destructive. Clients use these hints to decide what to confirm with the user.
- **Actionable error messages** — every error includes a `Suggestion: ...` hint pointing the agent toward the next thing to try (e.g., "Use editorial/list-posts to find the correct ID"). Modeled on the mcp-builder principle that errors should reduce the agent's debugging loop.

These changes are backward-compatible. Existing clients keep working; new clients get richer metadata.

---



## Why this exists

WordPress has a powerful REST API, but giving an AI client direct access to the REST API is awkward — there's no schema discovery, no permission contract, and exposing it on the open web requires careful application-password management.

The MCP Adapter solves this. It exposes WordPress *abilities* (small, typed, permission-checked operations) over MCP. Out of the box it ships with a handful of read-only core abilities. This plugin adds the writeable ones an editor actually needs.

---

## What's included

### 1. `enable-mcp-abilities`
A 10-line filter that marks every registered ability as public over MCP. **This is the "public flag" mechanism**, and it's worth understanding because it's the security boundary between "registered" and "exposed":

```php
add_filter( 'wp_ability_meta', function( $meta, $name ) {
    if ( ! isset( $meta['mcp'] ) ) $meta['mcp'] = array();
    $meta['mcp']['public'] = true;
    return $meta;
}, 10, 2 );
```

The MCP Adapter only exposes abilities with `meta.mcp.public = true`. Abilities without it are still registered in WordPress, still callable from PHP, but invisible to MCP clients. This plugin flips the flag for everything. If you'd rather opt-in per ability, remove this plugin and add the meta directly to specific `wp_register_ability()` calls.

> **Note**: "public" here means visible to MCP. Each ability still runs its own `permission_callback` (e.g. `current_user_can('edit_posts')`), so the connecting user must be an authenticated WP user with the right capabilities. Public ≠ unauthenticated.

### 2. `editorial-abilities`
Twenty editorial abilities organized into seven groups:

| Category | Abilities |
|---|---|
| **Posts** | `list-posts`, `get-post`, `create-post`, `update-post`, `delete-post` |
| **Pages** | `list-pages`, `get-page`, `create-page`, `update-page` |
| **Media** | `list-media` |
| **Site settings** | `get-settings`, `update-settings` |
| **Custom CSS** | `get-custom-css`, `set-custom-css` |
| **Footer JS** | `get-footer-js`, `set-footer-js` |
| **Taxonomy / theme** | `list-categories`, `list-tags`, `list-menus`, `get-theme-info`, `list-comments` |

Each ability uses a JSON Schema for input validation and runs under a strict permission callback.

#### Footer JS — why this exists
WordPress's `wp_kses_post()` strips `<script>` tags from any post or page content. This is correct security behavior. But it means an AI client can't add interactive behavior to a page just by editing the page body. The footer JS abilities solve this by storing JavaScript in an option (`mcp_footer_js`) and injecting it via the `wp_footer` hook on every page. The AI client gets a clean read/write surface for site-wide JS without bypassing the post sanitizer.

---

## Setup

### Prerequisites
- WordPress **6.9+** (the Abilities API was stabilized in 6.9)
- The [WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin installed and activated

### Installation
1. Drop both plugin folders into `wp-content/plugins/`:
   ```
   wp-content/plugins/enable-mcp-abilities/
   wp-content/plugins/editorial-abilities/
   ```
2. Activate both from `Plugins` in `/wp-admin`.
3. Verify the abilities are registered. From the WP admin or via WP-CLI:
   ```bash
   wp eval 'print_r( wp_get_abilities() );'
   ```
   You should see all `editorial/*` abilities listed.

### Connecting Claude
Add the MCP server to your Claude config (Claude Desktop, Claude Code, or any MCP client). The endpoint URL is whatever the MCP Adapter exposes — typically:
```
https://your-site.example.com/wp-json/mcp/v1
```
Authentication uses WordPress application passwords. See the MCP Adapter's docs for the current connection format.

---

## Available abilities (full reference)

<details>
<summary><strong>Posts</strong></summary>

- `editorial/list-posts` — paginated listing with status/search filters
- `editorial/get-post` — full content of one post by ID
- `editorial/create-post` — title, content, excerpt, status
- `editorial/update-post` — partial update by ID
- `editorial/delete-post` — soft-delete (moves to trash)

</details>

<details>
<summary><strong>Pages</strong></summary>

- `editorial/list-pages`
- `editorial/get-page`
- `editorial/create-page` — supports parent_id and slug
- `editorial/update-page`

</details>

<details>
<summary><strong>Media, settings, taxonomy</strong></summary>

- `editorial/list-media` — search by query and MIME type
- `editorial/get-settings` / `editorial/update-settings` — common site options (blogname, description, posts_per_page, timezone)
- `editorial/list-categories`, `editorial/list-tags`, `editorial/list-menus`, `editorial/list-comments`
- `editorial/get-theme-info`

</details>

<details>
<summary><strong>Customizer CSS</strong></summary>

- `editorial/get-custom-css` — returns the active theme's Additional CSS
- `editorial/set-custom-css` — replaces it via `wp_update_custom_css_post()`

</details>

<details>
<summary><strong>Footer JavaScript</strong></summary>

- `editorial/get-footer-js` — read the stored snippet
- `editorial/set-footer-js` — replace the stored snippet (rendered on every page via `wp_footer`)

</details>

---

## Architecture & design notes

### Hook order
Abilities **must** register on `wp_abilities_api_init`, not `init`. Categories must register on `wp_abilities_api_categories_init`. Registering on the wrong hook silently fails — the ability isn't visible to MCP and there's no error.

### Required category field
Each ability must include a `'category' => '...'` key. This was a soft requirement before WP 6.9 and a hard one starting in 6.9. Missing categories cause registration to fail silently.

### Permission model
Every ability uses a standard WP capability check:
- Posts: `edit_posts`, `publish_posts`, `delete_posts`
- Pages: `edit_pages`, `publish_pages`
- Media: `upload_files`
- Settings: `manage_options`
- CSS: `edit_css`
- Footer JS / menus / theme: `edit_theme_options`
- Comments: `moderate_comments`

If you connect an MCP client as an Editor, they get the editorial actions but not site settings. As an Administrator, they get everything.

### `wp_kses_post` and you
Post and page content is sanitized through `wp_kses_post()` on the way in, which strips `<script>`, `<style>`, `<iframe>`, and most data attributes. Plan around this:
- Site-wide JS goes through `editorial/set-footer-js`
- Site-wide CSS goes through `editorial/set-custom-css`
- Inline SVG in post content gets stripped — use `<img src="...">` with the SVG hosted in `/wp-content/uploads/` instead

---

## Example interactions

Once connected, an AI client can do things like:

```
"List my last 5 draft posts."
"Update post 142 — change the title to 'How I built my portfolio in 2026' and publish it."
"Replace the site's custom CSS with this CSS block: [...]"
"Add this JavaScript to the footer to enable smooth scrolling: [...]"
"Create a new page called 'Now' with this content: [...]"
```

---

## Extending

To add your own abilities, register them on the `wp_abilities_api_init` hook with a new or existing category, and they'll automatically be exposed over MCP (the `enable-mcp-abilities` plugin handles the public flag for you).

```php
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'mything/do-something', array(
        'label'       => 'Do Something',
        'description' => 'Does something useful.',
        'category'    => 'editorial',
        'input_schema' => array(
            'type' => 'object',
            'required' => array( 'name' ),
            'properties' => array(
                'name' => array( 'type' => 'string' ),
            ),
        ),
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'execute_callback' => function( $args ) {
            return array( 'message' => 'Hello, ' . $args['name'] );
        },
    ) );
} );
```

---

## Acknowledgments & Credits

**This project builds on:**

- The [Model Context Protocol](https://modelcontextprotocol.io/) — the open spec by Anthropic that defines how AI clients communicate with external tools.
- The [WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) — the plugin that actually exposes WordPress over MCP. This project adds writeable editorial abilities on top of it; the adapter handles authentication, schema validation, and HTTP transport.
- The WordPress Abilities API (core in WordPress 6.9+) — the underlying registration, permissioning, and execution layer that abilities plug into.

Without those three pieces, none of this would work. Credit where it's due.

**About the implementation:** This plugin was built collaboratively with [Claude](https://claude.ai) (Anthropic's AI assistant) — appropriate, given Claude is one of the MCP clients this plugin is designed to serve. The PHP in this repository was written by Claude under my direction. I made the architectural decisions (which 20 abilities to expose, how to organize them, the seven-category structure), debugged the issues that came up against my production site (the `wp_abilities_api_init` hook-order pitfall, the WP 6.9 category requirement, `wp_kses_post` stripping `<script>` tags from post content — which led to the footer-JS workaround), reviewed and tested every change, deployed it, and shipped it open-source.

That collaborative pattern is increasingly how software gets built in 2026. I'd rather be transparent about it than pretend otherwise — and frankly, the interesting work in a project like this is the design, debugging, and integration, not the typing.

**Used in production:** The portfolio site at [brodykretz.com](https://brodykretz.com) is edited entirely through this plugin — pages, posts, custom CSS, footer JS, the lot. If you visit it, you're looking at the output of these 20 abilities.

---

## License

MIT — see [`LICENSE`](LICENSE).

## Contributing

Issues and PRs welcome. The plugin is intentionally small and dependency-free; please keep it that way.
