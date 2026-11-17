# UI design spec: Yearly Leave Tally page (match this mockup exactly)

Build the Yearly Tally page and the shared app layout to match the approved mockup
described below. Use React + TypeScript + Tailwind + shadcn/ui. Put all colors in the
Tailwind theme as named tokens (no hardcoded hex values inside components).

## Overall feel
Clean, professional government office tool, read like a well-made official form
(redesign of 2026-11-12). Swiss/minimal: flat surfaces, hairline borders as the main
separator, no gradients, no glow, no emoji, no decorative icon tiles. One colour family —
the office blue — carries the sidebar and every action; colour is otherwise used only
for state (saved, warning, error) and to tell leave types apart. Dense but readable
tables, made for HR staff who encode all day. Every figure on a screen is real data.

**Signature detail (the only ornament):** page titles are set in Poppins and sit
on a hairline "form rule" with a short blue stroke at its start (`form-rule` utility),
like the ruled header of a CSC form. Do not reuse the rule elsewhere.

## Design tokens

All tokens live in `resources/css/app.css` (light in `:root`, dark in `.dark`).
Components use token classes only — never a raw hex or a Tailwind palette colour.

Fonts (Bunny Fonts, two families):
- `Source Sans 3` (400, 500, 600, 700) for all UI text, tables and figures.
  Numbers use tabular figures (`[data-numeric]`, tables, `.font-mono`); there is no
  separate monospace face.
- `Poppins` (600, 700) via `font-display` for page titles (`PageHeader`), the auth title and the
  product name in the sidebar only.
- Scale: 11 (`text-2xs`) / 12 / 13 / 14 (body) / 16 / 20 / 24–28 (page title, Poppins 600, -0.02em).

Colors (light / dark):
| Token | Light | Dark | Used for |
|---|---|---|---|
| page | #F3F5F7 | #0B121C | page canvas |
| card | #FFFFFF | #111A26 | cards, tables, inputs |
| foreground | #0F1C2E | #E6EAF0 | main text (ink) |
| muted-foreground | #556375 | #93A1B3 | labels, helper text |
| border / input | #DDE2E8 / #CBD3DC | #223044 / #2C3C52 | hairlines, field borders |
| primary | #005B99 | #5B9BD5 | buttons, links, focus ring, current month |
| sidebar | #005B99 | #0A1A2B | sidebar background |
| sidebar-accent | #1A6CA5 | #13283F | hover and active nav item |
| success | #1F7A4D | #3FB27F | saved / active states only |
| warning | #9A5B05 | #E3A33B | warnings, nearly-used credit |
| destructive | #B42318 | #F06C5E | errors, overdrawn, delete |
| table-head | #F1F4F7 | #142030 | table header cells |
| row-zebra / row-hover | #FAFBFC / #EEF4FA | #0F1823 / #172536 | rows |
| month-current | #EAF2F9 | #13243A | current month cells |

Each state colour has a `-surface` tint for backgrounds (e.g. `bg-success-surface`).

Radius: 6px buttons, inputs and badges (`rounded-md`), 8px cards and tables
(`rounded-lg`), 12px dialogs, the auth sheet and floating bars (`rounded-xl`).
Shadows only on floating layers (menus, dialogs, the unsaved-changes bar).

Leave type chip colors (background / text), loaded from the `leave_types.color` field,
with these as seeded defaults:
| Code | Name | Background | Text |
|---|---|---|---|
| VL | Vacation | #DCE8F7 | #163E6E |
| SL | Sick | #FBE3CF | #7A3A0B |
| MFL | Mandatory / Forced | #E8DFF5 | #4A2A78 |
| SPL | Special Privilege | #D2EEE8 | #0F5247 |
| CTO | Compensatory Time-Off | #E4E7EA | #2E343A |
| WL | Wellness | #DDF0D9 | #24561E |

Chips keep a 4px radius.

## Brand and landing page
- **The mark is the official seal of the City of Tanjay**, served from
  `public/images/brand/` (`tanjay-seal-96/192/256.png`, transparent) through the
  `TanjaySeal` component, which picks the file for the size. It appears in the sidebar
  on every page, on the landing page, and as the favicon / home-screen icon. Never
  recolour, crop or redraw it, and never place it on a busy background other than the
  landing gradient.
- **Wordmark:** "HRMD" over "LGU TANJAY CITY", set in Poppins 700 in `landing-ink`. The
  sidebar uses the small form: "HRMD · LGU Tanjay City" above "Leave Tally Sheet".
- **Landing page (sign-in and password screens)** follows the HRMD banner: the
  `landing-canvas` gradient (lavender `--landing-from` → `--landing-via` → light blue
  `--landing-to`, deep indigo to navy in dark mode) with `NetworkBackground`, a canvas
  of slowly drifting points joined by fine lines in `--landing-line`. Brand on the left,
  the sign-in sheet on the right; stacked on phones. The animation stops when the tab
  is hidden, is drawn still under "reduce motion", ignores the pointer, and is hidden
  from screen readers. It is used on the landing page only — never inside the app.

## App layout
Two columns: sidebar on the left, main content on the right. On small screens
the sidebar stacks above the content (flex-wrap), never fixed or 100vh.

### Sidebar (width ~240px, background `sidebar`, padding 24px 16px)
- Top: a flat white seal tile with the mark, then a small uppercase "HRMD" label and
  "Leave Tally Sheet" in Poppins. No gradient, no badge.
- The sidebar is collapsed from the top bar's toggle only (one control, not three).
- Nav links with lucide icons (18px) + label, 14px, padding 10px 12px, radius 6px:
  Tally sheet, Employees, Leave types, Holidays, Reports, Activity log.
  - Default: `sidebar-text`; hover: `sidebar-hover` bg + white text.
  - Active: `bg-page` background, `text` color, 600 weight.
- Bottom (pushed down with margin-top auto, top border `sidebar-divider`):
  "Signed in as **{user name / role}**".
- Hide admin-only links for HR Staff.

### Main content (padding 28px 32px, gap 20px between sections)

1. **Page header** (flex, wraps on small screens). The title row with its action
   buttons sticks under the top bar while the page scrolls (hairline bottom border once
   pinned); the breadcrumb/eyebrow and subtitle scroll away:
   - Left: breadcrumb "Leave monitoring › Tally sheet" (13px, muted),
     title "Leave Tally Sheet — {year}" (28px, 700),
     subtitle "Human Resource Management Division · As of {today}" (14px, muted).
   - Right: three buttons, 44px tall:
     - "Print tally (PDF)" (outline, printer icon)
     - "Export Excel" (outline, download icon)
     - "Add leave" (primary green, plus icon), which opens the Add Leave panel

2. **Filter bar** (flex-wrap, gap 12px):
   - "Year" select (44px tall)
   - Search input with a search icon, placeholder "Search name or position",
     ~260–380px wide, debounced, filters on the server
   - Office filter as pill buttons ("All offices", then each office). Active pill
     = dark (`text` bg, white text); others = white with border. Use `aria-pressed`.
   - Add A–Z letter tabs (from the paper sheet's alphabetical sections), shown as a
     compact row of letter buttons under the filters; the active letter is highlighted.

3. **Legend row**: "Codes" label then one chip per leave type ("VL Vacation", …),
   ending with muted text "· AM / PM = half day".

4. **Content row** (flex-wrap, gap 20px): the tally table card takes the remaining
   width; the Add Leave panel sits to the right when open (or use a shadcn Sheet).

## Tally table (white card, 1px border, radius 8px, horizontal scroll inside the card)
Two header rows:
- Row 1: NO. | EMPLOYEE | POSITION TITLE | SG | RATE (each rowSpan=2),
  then a grouped header "MONTH — DAYS + LEAVE CODE" spanning 12 columns,
  then a grouped header "TOTAL DAYS ({year})" spanning (leave types + 1) columns.
- Row 2: JAN … DEC, then one column per leave code, then "ALL".
- Header cells: `table-head` bg, `text-secondary`, 12px, 600, letter-spacing .04em,
  uppercase. Group headers centered on `table-group-head`.
- The current month's header cell = `primary` background with white text.

Body rows:
- NO.: muted, mono.
- EMPLOYEE: "LASTNAME, Firstname M." in 600, with the office name below in 12px muted.
- POSITION TITLE: no wrap.
- SG and RATE: right-aligned, mono, `tabular-nums`; rate formatted "₱14,267.00".
  Hide the RATE column for roles not allowed to see salary.
- Month cells (min-width ~112px): show chips, one per leave type in that month.
  Chip = days in mono 600 + code, e.g. **23–25** MFL, **9** SL, **13 PM** CTO.
  Text comes from the backend `LeaveTallyService`; the frontend never computes it.
  Current-month cells use `month-current` bg; future months use `month-future` bg.
- Hovering a month cell shows a small dashed "+" button (28px) in the bottom-right
  corner. Clicking it opens Add Leave with that employee and month preselected.
  The button must also show on keyboard focus and have an aria-label like
  "Add leave for ABALOS, Maria in MAR".
- Clicking a chip opens view/edit/delete for that entry.
- Total columns: centered, mono; show "–" for zero and one decimal for half days (1.5).
  The "ALL" column uses `total-grand` bg and 600 weight.
- Zebra rows (`row-zebra`), hover highlight (`row-hover`), row dividers (`row-border`).
- Sticky: the header rows stick to the top; the NO. and EMPLOYEE columns stick to the
  left with a 1px right border, so names stay visible while scrolling across months.
- Density: comfortable (10px cell padding) by default, plus a "compact" toggle
  (5px 8px) saved per user.

Footer bar inside the card (13px, muted, top border):
- Left: "Showing {n} of {total} employees · Sorted by last name"
- Right: pagination controls
- Empty state when nothing matches: centered muted text
  "No employees match this search or office." with a "Clear filters" button.
- Loading state: skeleton rows the same height as real rows.

## Add Leave panel (card ~320–400px wide, or a shadcn Sheet from the right)
- Header: "Add leave" (18px, 700) and a 44×44 close (X) button with an aria-label.
- Fields (labels 13px, 500; inputs 44px tall, 1px border, radius 6px):
  1. Employee: searchable combobox ("LASTNAME, Firstname — Position")
  2. Leave type: select showing "VL — Vacation" etc. (active types only)
  3. From / To dates side by side in a 2-column grid
     Helper text: "Weekends and holidays in the range are skipped automatically."
  4. Day part: segmented control with Whole day / AM / PM (radio inputs)
  5. Remarks (optional textarea), placeholder "e.g. Approved leave form no. …"
- Before saving, show a preview list of the actual dates that will be saved.
- Footer buttons, right-aligned: "Cancel" (outline) and "Save leave" (primary).
- Show server validation errors inline (e.g. "Already has SL on Mar 9, 2026").
- On success: close the panel, refresh the table, and show a toast
  "Leave saved for ABALOS, Maria Irene".

## Accessibility and behavior
- Real `<button>`, `<a>`, `<label>` and `<input>` elements; never clickable divs.
- Visible focus rings (primary color); everything works by keyboard
  (Tab, Enter to open, Esc to close the panel).
- Text contrast at least 4.5:1; click targets at least 44px (except the in-cell "+").
- Icons from lucide-react, decorative icons `aria-hidden`.
- Responsive: desktop first; on small screens the sidebar stacks, the header buttons
  wrap, and the table scrolls horizontally inside its card.

## Build it as reusable components
`AppLayout`, `Sidebar`, `PageHeader`, `FilterBar`, `OfficePills`, `LetterTabs`,
`LeaveLegend`, `LeaveChip`, `TallyTable`, `TallyMonthCell`, `AddLeaveSheet`,
`EmptyState`, `TableSkeleton`. Reuse `PageHeader`, `FilterBar`, `EmptyState` and
`TableSkeleton` on the Employees, Leave types, Holidays and Activity log pages so the
whole app looks consistent.
