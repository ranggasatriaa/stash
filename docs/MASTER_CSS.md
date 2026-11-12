# Stash Master CSS

`resources/css/stash.css` is the single source of truth for Stash's look. It is
plain CSS (custom properties + cascade layers) with **no Tailwind or build
dependency**, so you can drop it into any other website.

- Live reference: open [`docs/styleguide/index.html`](styleguide/index.html) in a
  browser (it links the CSS file directly).
- Design source: the "Stash / Foundations & Controls" and "Content & Feedback"
  reference sheets.

## Use it on another site

```html
<!-- 1. Font (any of these) -->
<link rel="stylesheet" href="/fonts/nunito-sans.css" />
<!-- self-hosted woff2 -->
<!-- or: npm i @fontsource-variable/nunito-sans && import '@fontsource-variable/nunito-sans' -->

<!-- 2. The master CSS -->
<link rel="stylesheet" href="/css/stash.css" />

<body class="st-body">
    <button class="st-btn st-btn--primary">+ Save</button>
</body>
```

With a bundler: `@import 'stash.css';`. With Tailwind 4, declare the layer order
first so utilities can still refine components:

```css
@layer theme, base, st-base, components, st-components, utilities;
@import 'tailwindcss';
@import './stash.css';
```

Everything is namespaced with `st-` so it will not collide with existing CSS.
Base element styles only apply inside `.st-body` (nothing global is reset).

## Tokens (CSS custom properties on `:root`)

| Group    | Tokens                                                                                                                                                                |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Palette  | `--st-ivory #FFF8EF`, `--st-white`, `--st-ink #191A1D`, `--st-orange #FF7417`, `--st-yellow #FFD83D`, `--st-muted #62656D`                                            |
| Derived  | `--st-orange-hover`, `--st-orange-text #C2410C` (text on light, AA), `--st-yellow-soft`, `--st-focus #E35D00`, `--st-line-soft`, `--st-surface-hover`, disabled tones |
| Semantic | `--st-success/-soft/-line`, `--st-info…`, `--st-warning…`, `--st-danger…`                                                                                             |
| Aliases  | `--st-canvas`, `--st-surface`, `--st-text`, `--st-text-muted`, `--st-line`, `--st-sidebar`                                                                            |
| Type     | `--st-font-sans`, `--st-text-heading 32`, `--st-text-section 24`, `--st-text-card 20`, `--st-text-body 16`, `--st-text-label 14`, `--st-text-meta 12`                 |
| Shape    | `--st-border 2px`, `--st-radius 12px` (controls), `--st-radius-card 20px`, `--st-shadow 4px 4px 0 ink`, `--st-shadow-hover 6px 6px`, `--st-shadow-press 1px 1px`      |
| Space    | `--st-space-1…7` = 4, 8, 12, 16, 24, 32, 48 px                                                                                                                        |
| Size     | `--st-touch 44px`, `--st-control-h 44px`, `--st-sidebar-w`, `--st-detail-w`                                                                                           |

Re-theme by overriding tokens, e.g. `:root { --st-orange: #ff5f1f; --st-orange-text: #b53c0c; }`
(re-check contrast when you change text/fill pairs).

## Components

| Block                                                 | Modifiers / elements                                                                                                                            | Reference    |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| `.st-btn`                                             | `--primary --secondary --selected --danger --danger-solid --ghost --on-dark --sm --lg --icon --block`, `[disabled]`, `[aria-busy=true]` spinner | 01·05        |
| `.st-link`                                            | `--plain`                                                                                                                                       | 01·06        |
| `.st-field`                                           | `__label __hint __error`                                                                                                                        | 01·08        |
| `.st-input` `.st-textarea` `.st-select`               | `[aria-invalid=true]`, `:disabled`, `.st-input--sm`                                                                                             | 01·08        |
| `.st-search`                                          | `__icon __clear`                                                                                                                                | 01·08        |
| `.st-check` + `.st-checkbox` `.st-radio` `.st-switch` | native inputs, `:checked`, `:indeterminate`                                                                                                     | 01·09        |
| `.st-segmented`                                       | `__item[aria-pressed                                                                                                                            | aria-checked | aria-selected=true]` | 01·09 |
| `.st-tag`                                             | `--orange --white --soft --selected`, `__remove`; `a/button.st-tag` are interactive; `.st-tags` wrapper                                         | 01·07        |
| `.st-badge`                                           | `--unread --read --archived --trash --warning --type --outline`                                                                                 | 01·07        |
| `.st-chip`                                            | `__label __remove` (active filters)                                                                                                             | —            |
| `.st-panel`                                           | `--flat`, `__header __body`                                                                                                                     | —            |
| `.st-card`                                            | `--interactive --selected --loading`, `__media __fallback __body __title __desc __host __meta __overlay(--tl/--tr) __select`                    | 02·01        |
| `.st-skeleton`                                        | shimmer placeholder                                                                                                                             | 02·01        |
| `.st-table-wrap` `.st-table`                          | `tr[aria-selected=true]`                                                                                                                        | 02·02        |
| `.st-pagination`                                      | `__page[aria-current=page]`                                                                                                                     | 02·02        |
| `.st-alert`                                           | `--info --warning --error`, `__icon __content __title __body __action`                                                                          | 02·03        |
| `.st-toast`                                           | `--info --warning --error`, `__icon __message __action __close`                                                                                 | 02·04        |
| `.st-menu`                                            | `__item(--danger, [data-highlighted]) __label __separator`                                                                                      | 02·07        |
| `.st-tooltip`                                         | —                                                                                                                                               | 02·07        |
| `.st-overlay` `.st-dialog`                            | `--sm --lg`, `__header __title __description __body __footer`                                                                                   | 02·05/09     |
| `.st-sheet`                                           | `--right --left --bottom --full --dark`, `__header __body __footer`                                                                             | —            |
| `.st-tabs` `.st-tab`                                  | `--fill`, `[aria-selected=true]`                                                                                                                | 02·09        |
| `.st-breadcrumb`                                      | `__sep`                                                                                                                                         | 02·09        |
| `.st-dropzone`                                        | `--active --invalid`, `__title __hint`                                                                                                          | 02·09        |
| `.st-progress`                                        | `__bar`                                                                                                                                         | 02·08        |
| `.st-empty`                                           | `__icon __title __body`                                                                                                                         | 02·08        |
| `.st-brand`                                           | `__mark __word __slogan`, `--sm`                                                                                                                | 01·02        |
| `.st-sidebar` `.st-nav`                               | `__item[aria-current=page] __label __count __dot __heading __separator`                                                                         | 01·02        |
| `.st-nav-light__item`                                 | light nav states                                                                                                                                | 01·02        |
| `.st-topbar` `.st-bottomnav`                          | `__item[aria-current=page]`                                                                                                                     | 01·03        |
| Layout                                                | `.st-toolbar .st-section-title .st-grid .st-stack .st-cluster`                                                                                  | —            |
| Content                                               | `.st-markdown .st-code .st-kbd .st-spinner .st-icon-btn`                                                                                        | —            |
| Text                                                  | `.st-h1 .st-h2 .st-h3 .st-text .st-label .st-meta .st-muted .st-truncate .st-clamp-2/3 .st-break .st-sr-only`                                   | 01·01        |

## Accessibility built in

- 44 px minimum targets for buttons, nav and inputs.
- Visible `:focus-visible` ring (`--st-focus`, ≥ 3:1 on all surfaces).
- Status colours always paired with text/icons; alert/toast roles are up to the markup.
- `prefers-reduced-motion` removes lifts, presses and animations; `forced-colors`
  keeps borders visible.
- Inputs use 16 px text to prevent mobile zoom.
