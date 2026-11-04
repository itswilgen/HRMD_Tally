# UI design spec: Yearly Leave Tally page (match this mockup exactly)

Build the Yearly Tally page and the shared app layout to match the approved mockup
described below. Use React + TypeScript + Tailwind + shadcn/ui. Put all colors in the
Tailwind theme as named tokens (no hardcoded hex values inside components).

## Overall feel
Clean, professional government office tool. Flat design: no gradients, no heavy shadows,
no emoji. Calm neutral background, one green accent, and color used ONLY to tell leave
types apart. Dense but readable tables, made for HR staff who encode all day.

## Design tokens

Fonts (Google Fonts):
- `IBM Plex Sans` (400, 500, 600, 700) for all UI text
- `IBM Plex Mono` (500, 600) for numbers, days, and leave codes

Colors:
| Token | Hex | Used for |
|---|---|---|
| bg-page | #F4F5F2 | page background |
| surface | #FFFFFF | cards, table, inputs |
| sidebar | #005B99 (light) / #1F3B33 (dark) | sidebar background |
| sidebar-hover | #06456F (light) / #2A4A41 (dark) | sidebar link hover |
| sidebar-divider | #1F74AD (light) / #355A4F (dark) | sidebar footer border |
| sidebar-text | #C9D6D0 | sidebar links |
| sidebar-label | #A9BDB4 | small "HRMD" label |
| primary | #1F5F4A | primary buttons, current month header, links |
| primary-hover | #174B3A | primary button hover |
| text | #1B1F24 | main text |
| text-secondary | #3A424B | labels, table headers |
| text-muted | #5B6470 | breadcrumbs, helper text, row numbers |
| border | #C8CDC6 | inputs, buttons, card borders |
| table-head | #E9ECE6 | table header cells |
| table-group-head | #DFE3DC | grouped header ("MONTH…", "TOTAL…") |
| row-border | #E3E6E0 | row dividers |
| row-zebra | #FAFBF8 | even rows |
| row-hover | #F1F5F2 | row hover |
| month-current | #F2F8F5 | current month cells |
| month-future | #F6F6F3 | future month cells |
| total-grand | #EEF2EC | grand total column |

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

Radius: 6px for buttons/inputs, 8px for cards, 4px for chips, full for filter pills.

## App layout
Two columns: sidebar on the left, main content on the right. On small screens
the sidebar stacks above the content (flex-wrap), never fixed or 100vh.

### Sidebar (width ~240px, background `sidebar`, padding 24px 16px)
- Top: small uppercase label "HRMD" (12px, letter-spacing .08em, `sidebar-label`, 600),
  under it "Leave Tally" (18px, 700, white).
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
