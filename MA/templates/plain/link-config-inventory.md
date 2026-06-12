# link-config Section — ID & Tag Inventory

## Container Structure
| Element | Classes | ID | Notes |
|---------|---------|----|-------|
| `<div>` | `link-config` | — | Main wrapper |
| `<div>` | `link-header` | — | Header bar |
| `<div>` | `link-title` | — | Title area (currently empty) |
| `<div>` | `link-grid` | — | Grid layout container |
| `<div>` | `link-field` | — | Per-field wrapper (template) |
| `<div>` | `link-field` | `adminUploadGroup` | Upload section wrapper (hidden by default) |
| `<div>` | `link-field` | — | Per-field wrapper (redirectType) |
| `<div>` | `link-field` | `redirectUrlGroup` | Redirect URL wrapper |

## IDs — All 8 Unique Identifiers
| ID | Tag | Purpose | Referenced in JS |
|----|-----|---------|------------------|
| `template` | `<select>` | Page template selector | Line 2362, 2704, 2764, 3288 |
| `adminUploadGroup` | `<div>` | Upload section container | Line 3289 |
| `uploadForm` | `<form>` | Upload form | Line 3303-3305, 3338 |
| `uploadBtn` | `<button>` | Upload submit button | Line 3307 |
| `uploadPreview` | `<img>` | Background preview image | Line 3296 |
| `redirectType` | `<select>` | Redirect type (custom/error_page) | Line 2706, 2753 |
| `redirectUrlGroup` | `<div>` | Redirect URL input wrapper | Line 2707, 2755 |
| `redirectUrl` | `<input type="url">` | Custom redirect URL value | Line 2363, 2708, 2757, 2759, 2765 |

## JS Event Listeners Bound to These Elements
| ID | Event | Line |
|----|-------|------|
| `template` | `change` | 3301 → calls `updateAdminUploadVisibility()` |
| `uploadForm` | `submit` | 3305 → async upload handler |
| `redirectType` | `change` | 2753 → toggles URL group visibility |

## CSS Classes Used
| Class | Defined at line |
|-------|----------------|
| `.link-config` | 1711 |
| `.link-header` | 1717 |
| `.link-title` | 1726 |
| `.link-grid` | 1750 |
| `.link-field` | 1756 |
| `.link-icon` | 1737 (unused in HTML) |
| `.preview` | Used inline |
