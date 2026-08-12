# Catalog Filters — Complete User Guide

> **Who this guide is for:** anyone working with the plugin for the first time. Everything is explained as simply as possible — step by step, with examples. Read it once and you won't have any questions left.

---

## Table of contents

1. [What filters are and why you need them](#1-what-filters-are-and-why-you-need-them)
2. [How to open the plugin](#2-how-to-open-the-plugin)
3. [Getting to know the interface](#3-getting-to-know-the-interface)
4. [Configuring filters manually](#4-configuring-filters-manually)
5. [Filter types in detail](#5-filter-types-in-detail)
6. [Settings inheritance](#6-settings-inheritance)
7. [Filter preview](#7-filter-preview)
8. [Copying settings between categories](#8-copying-settings-between-categories)
9. [Change history](#9-change-history)
10. [Bulk import from Excel / CSV](#10-bulk-import-from-excel--csv)
11. [Exporting current settings](#11-exporting-current-settings)
12. [Diagnostics and maintenance](#12-diagnostics-and-maintenance)
13. [Webhook — automatic site refresh](#13-webhook--automatic-site-refresh)
14. [Plugin settings](#14-plugin-settings)
15. [Common questions and issues](#15-common-questions-and-issues)
16. [Glossary](#16-glossary)

---

## 1. What filters are and why you need them

Imagine a customer opens the "Reels" section on your site. There are 500 products. How do they quickly find the reel they need?

**Filters** are a sidebar on the site that lets customers narrow down products:

```
☑ Brand:
  ☑ Shimano
  □ Daiwa
  □ Rapala

━━━●━━━━━━━━●━━
Price: $50 — $500

○ Type:
  ● Spinning
  ○ Baitcasting
```

The customer picks "Shimano", sets a price range up to $500 — and instead of 500 products sees only 12 relevant ones. Convenient!

**The Catalog Filter Config plugin** lets you:

- Configure **which filters** to show in each catalog section
- Control the **order** of filters
- Configure **all categories at once** via an Excel spreadsheet
- See a **preview** of filters with real data
- **Automatically refresh** the site after every change

---

## 2. How to open the plugin

1. Log in to the **WordPress dashboard** (usually: `your-site.com/wp-admin`)
2. In the left menu find the **WooCommerce** section
3. Click **Catalog Filters**

You'll land on the plugin page.

> **Important:** The plugin needs an active **WooCommerce** installation and at least one product category. If there are no categories — create them first under **WooCommerce → Products → Categories**.

---

## 3. Getting to know the interface

The plugin page has **five tabs**:

```
[ 🎛 Filters ] [ 📤 Import / Export ] [ 🔍 Diagnostics ] [ ⚙️ Settings ]
```

### The "Filters" tab (main)

Split into two parts:

**Left panel — category tree:**

```
📁 Fishing reels
  📂 Spinning  ●
  📂 Baitcasting
  📂 Fly reels
📁 Rods
  📂 Spinning  ●
  📂 Feeder
```

The `●` icon means a category has its own filter settings.

**Right panel — settings of the selected category:**

- List of configured filters
- Buttons "+ Add", "Preview", "Copy to...", "History"
- The "Save" button

---

## 4. Configuring filters manually

### Step 1: Select a category

Find the category you need in the left panel and **click its name**.

If there are many categories — use the search field at the top of the left panel.

### Step 2: Review the current filters

On the right you'll see the current filters for this category (if they were already configured) or an empty list with a "+ Add filter" button.

### Step 3: Add a filter

Click **"+ Add filter"**. A new row appears with these fields:

#### The "Filter name" field (required)

This is what the **customer sees** on the site.

| What you're configuring | Write     |
| ----------------------- | --------- |
| Filter by brand         | Brand     |
| Filter by price         | Price     |
| Filter by color         | Color     |
| Filter by weight        | Test (g)  |
| Filter by type          | Reel type |

#### The "WooCommerce attribute" field (required)

This is the **technical name** of the data source — where the filter options come from.

Click the field — a dropdown of all your attributes appears. Pick the one you need:

| You want to filter by... | Select the attribute     |
| ------------------------ | ------------------------ |
| Brand                    | `pa_brand` (Brand)       |
| Color                    | `pa_color` (Color)       |
| Material                 | `pa_material` (Material) |
| Price                    | `price` (built-in Price) |
| Weight / test            | `pa_test` or `pa_weight` |

> **Where do attributes come from?** From **WooCommerce → Products → Attributes**. If the attribute you need isn't there — create it, assign it to products, and it will appear in the list.

#### The "Type" field

How the filter looks visually. See more in [section 5](#5-filter-types-in-detail).

#### The "Min" and "Max" fields (only for the "range" type)

The initial range for the price slider. For example: Min = 500, Max = 50000.

> For price (`price`) you can leave these empty — the plugin will automatically determine the range from the real product prices.

#### The "Order" field

A number that determines the filter's position in the list. 1 — first (top), 2 — second, and so on.

#### The "On/Off" toggle

Enabled (green) — the filter shows on the site.
Disabled (gray) — the filter is hidden (but the settings are kept).

#### The "Min. products" field

The minimum number of products at which a filter option is shown. For example: "2" — if the "Shimano" option has only 1 product, it won't appear. Usually set to 0 (always show).

### Step 4: Sorting filters

To change the order of filters — **drag** a filter row by the `⠿` handle (on the left side of the row).

### Step 5: Save

Click the **"Save"** button in the top-right corner of the filters panel.

After saving, the plugin:

- Writes the settings to the database
- Clears the cache for this category
- Sends a notification to the site (if a webhook is configured)

> **The "Reset to inheritance" button** removes all of this category's own settings and restores inheritance from the parent category. See more in [section 6](#6-settings-inheritance).

---

## 5. Filter types in detail

### checkbox — Checkboxes (multiple choice)

```
☑ Brand:
  ☑ Shimano    (45 products)
  ☑ Daiwa      (32 products)
  □ Rapala     (18 products)
  □ Abu Garcia (7 products)
```

**When to use:** when the customer wants to select several options at once.

**Examples:** Brand, Color, Material, Series, Country of origin.

### radio — Radio buttons (one option)

```
○ Rod action:
  ● Fast
  ○ Medium-fast
  ○ Slow
```

**When to use:** when only one option out of several should be selected.

**Examples:** Action, Type, Length (specific), Skill level.

### range — Slider (range)

```
━━━●━━━━━━━━━●━━━
Price: $150 — $800
```

**When to use:** for numeric characteristics with a from/to range.

**Examples:** Price, Weight, Rod length, Test (min and max), Reel capacity.

**Important:** for the `price` attribute you can leave min/max empty — they are determined automatically from the real product prices in the category.

### toggle — Switch (yes/no)

```
🔘 In stock: [ On ]
🔘 On sale:  [ Off ]
```

**When to use:** for binary filters (yes or no).

**Examples:** In stock, On sale, Bestseller, New.

### color — Color swatches

```
Color: 🔴 🔵 🟢 ⚫ ⚪
```

**When to use:** for color attributes, when the visual presentation matters.

**Examples:** Lure color, Line color, Case color.

---

## 6. Settings inheritance

### What is inheritance?

If you configure filters for a parent category — all nested (child) categories **automatically get the same filters** with no extra work.

```
Fishing reels             ← configured: Price, Brand, Type
  ├── Spinning            ← automatically: Price, Brand, Type
  ├── Baitcasting         ← automatically: Price, Brand, Type
  └── Fly reels           ← overridden: Price, Brand (no Type, added Material)
```

### How it works in practice

**Scenario 1 — configure once for all:**

You have a "Rods" category with 10 subcategories. Configure the "Price" and "Brand" filters for "Rods" — and all 10 subcategories immediately get these filters.

**Scenario 2 — override for a specific category:**

"Spinning rods" needs an "Action" filter that the others don't have. Open that category, add your filters, and save. Now it has **its own** settings instead of the inherited ones.

**Scenario 3 — revert to inheritance:**

You configured "Feeder rods" separately but want to bring back inheritance from the parent. Open the category and click **"Reset to inheritance"** — the custom settings are removed.

> **Tip:** Configure common filters (Price, Brand) at the top category level, and specific ones (Action, Test, Length) only at the level of specific subcategories.

---

## 7. Filter preview

Before filters go live on the site — check how they'll look with real data.

### How to open the preview

1. Open any category
2. Click the **"Preview"** button
3. A modal window opens

### What the preview shows

- **Checkboxes and radio buttons:** the real attribute values of your products with counts (`Shimano — 45 products`)
- **Slider:** the real minimum and maximum of prices/characteristics
- **Empty filters:** if there are no options — it means the products don't have the corresponding attributes filled in

### What to do if a filter is empty in the preview

1. Make sure the attribute is specified correctly (e.g. `pa_brand`, not `brand`)
2. Open any product from this category and check whether the attributes are filled in
3. If the attributes are empty — fill them in for the products under **WooCommerce → Products**

---

## 8. Copying settings between categories

If several categories should have identical or similar filters — use copying.

### How to copy

1. Open the category whose settings you want to copy
2. Click **"Copy to..."**
3. In the window that opens:
   - Use the search to find the categories you need
   - Check the target categories
   - Or click "Select all" for a quick selection
4. If the categories already have settings — decide what to do:
   - **Skip** — existing settings stay untouched
   - **Overwrite** — the current settings are replaced (the previous ones are saved to history)
5. Click **"Copy"**

> **Tip:** Create a "reference" category with the perfect filters and copy its settings to all the categories you need in one click.

---

## 9. Change history

Every filter save is automatically recorded in the history. It's insurance against accidental changes.

### How to view the history

1. Open a category
2. Click the **"History"** button (it appears if there are saved versions)
3. A list opens: who changed what and when

### How to restore an old version

1. Find the version you need in the list
2. Click **"Restore"**
3. Confirm the action

The plugin reverts the settings to the selected version and saves the current state as a new history entry.

> **Retention:** history is kept for 90 days, up to 20 versions per category. Older entries are deleted automatically.

---

## 10. Bulk import from Excel / CSV

This method is best when you need to configure filters for **many categories** at once. Instead of opening each category manually — you fill in a spreadsheet in Excel and upload it as a single file.

### When to use import

- You have 20+ categories and need to configure them in one go
- You're migrating settings from another site
- You need to make the same changes across many categories

### Step 1: Download the template

1. Open the **"Import / Export"** tab
2. Click **"📥 Download Excel template"**
3. Save the `filter-config-template.xlsx` file to your computer

### Step 2: Study the template

Open the file in Excel, LibreOffice Calc or Google Sheets. The template has 4 sheets:

**Sheet 1: "Filters"**
This is where you fill in the data. The top rows are examples (delete them before importing).

Columns:

| Column            | What to enter                   | Example          |
| ----------------- | ------------------------------- | ---------------- |
| **Category**      | Exact WooCommerce category name | `Spinning reels` |
| **Filter**        | Customer-facing name            | `Brand`          |
| **Type**          | Filter type                     | `checkbox`       |
| **WC Attribute**  | Attribute slug                  | `pa_brand`       |
| **Min**           | Minimum (range only)            | `500`            |
| **Max**           | Maximum (range only)            | `50000`          |
| **Order**         | Position in the list            | `1`              |
| **Enabled**       | Yes/No                          | `Yes`            |
| **Min. products** | Hide if fewer than N products   | `0`              |

**Sheet 2: "WooCommerce Categories"**
A list of all categories in your store with their names. Use it to copy the exact spelling of names into the "Category" column.

**Sheet 3: "WooCommerce Attributes"**
A list of all attributes. Use it to copy the exact slug into the "WC Attribute" column.

**Sheet 4: "Filter types"**
A description of each type with examples.

### Step 3: Fill in the spreadsheet

Open the **"Filters"** sheet and fill in the data.

**Example of a correctly filled spreadsheet:**

```
Category         | Filter | Type     | WC Attribute | Min  | Max   | Order | Enabled
-----------------|--------|----------|--------------|------|-------|-------|--------
Spinning reels   | Price  | range    | price        | 500  | 15000 | 1     | Yes
Spinning reels   | Brand  | checkbox | pa_brand     |      |       | 2     | Yes
Spinning reels   | Series | checkbox | pa_series    |      |       | 3     | Yes
Spinning rods    | Price  | range    | price        | 1000 | 50000 | 1     | Yes
Spinning rods    | Brand  | checkbox | pa_brand     |      |       | 2     | Yes
Spinning rods    | Action | radio    | pa_action    |      |       | 3     | Yes
```

> **Rule:** each filter gets its own row. If a category has 3 filters — that's 3 rows with the same category name.

#### Filling rules (important!)

**Category:**

- Must exactly match the name in WooCommerce (case doesn't matter)
- Correct example: `Spinning reels`
- Wrong example: `spinning reels ` (extra trailing space!)
- Check against the "WooCommerce Categories" sheet

**Type:**

- Write in lowercase: `checkbox`, `range`, `radio`, `toggle`, `color`
- If left empty — it defaults to `checkbox`

**WC Attribute:**

- Copy it exactly from the "WooCommerce Attributes" sheet
- For price use: `price`
- For the "Brand" attribute (slug: brand) use: `pa_brand` (WooCommerce adds the `pa_` prefix)

**Enabled:**

- You can write: `Yes`, `yes`, `1`, `true` — all of these mean "enabled"
- You can write: `No`, `no`, `0`, `false` — all of these mean "disabled"
- If empty — treated as "enabled"

### Step 4: Save the file

Save it as **XLSX** (Excel) or **CSV** (a text file with delimiters).

**For CSV:**

- Column delimiter: `;` or `,` (the plugin detects it automatically)
- Encoding: **UTF-8**
- The first row is the headers (the same as in Excel)

### Step 5: Upload the file

Go to the **"Import / Export"** tab and find the "Upload the file" block.

**Method 1 — drag:**
Drag the file straight into the gray dashed zone ("Drag a file here"). The zone lightens up — drop the file.

**Method 2 — select:**
Click the "select a file" link inside the zone — the standard file picker opens.

After selecting, you'll see the file name and a "Check file →" button.

**Also — import mode:**
Choose what to do with already-configured categories:

- **Skip** — if the category already has filters, the new rows from the file are ignored
- **Overwrite** — the old filters are replaced with the ones in the file (old versions are saved to history)

### Step 6: Review the preview

Click **"Check file →"**. The plugin:

1. Reads the first 5 rows of your file
2. Validates each row on the server:
   - Does such a category exist in WooCommerce?
   - Is such an attribute registered in WooCommerce?
3. Shows a preview table — exactly how the plugin interpreted your file
4. Shows warnings (if any)

**Warnings and what to do about them:**

| Warning                                | What happened                       | What to do                                           |
| -------------------------------------- | ----------------------------------- | ---------------------------------------------------- |
| "Category \"X\" not found"             | Typo or extra spaces                | Check the spelling against the "Categories" sheet    |
| "Attribute \"pa_X\" is not registered" | Wrong slug or attribute not created | Check the "Attributes" sheet or create the attribute |
| "Skipped — filter name missing"        | The "Filter" column is empty        | Fill in the filter name                              |
| "Unknown type \"X\""                   | Typo in the type                    | Use: checkbox, range, radio, toggle, color           |

If there are errors — close the window, fix the file, and repeat step 5.

### Step 7 (optional): Dry run

If you want to make sure everything is correct **without real changes** — check the **"Test mode (validate only, save nothing)"** box.

During a dry run the plugin:

- Processes the whole file
- Shows what would have been imported
- **Does not save** any changes to the database

Once you're sure everything is OK — uncheck the box and run the real import.

### Step 8: Run the import

Click **"Import all rows"**.

The plugin processes the whole file and shows the result:

```
✅ Imported:  15 categories
⏭ Skipped:    3 (already configured, "Skip" mode)
❌ Errors:     1 (Row 7: category not found)
```

Below is a detailed table for each category.

### After the import

Click **"Import more"** if you need to upload another file, or go to the **"Filters"** tab to check the result manually.

---

## 11. Exporting current settings

You can download all current filters — for a backup, to hand off to another person, or for bulk editing.

### Export to Excel (XLSX)

1. Go to the **"Import / Export"** tab
2. Click **"📤 Export current filters"**
3. A `filter-config-export-YYYY-MM-DD.xlsx` file downloads

### Export to CSV

1. Click **"📄 Export to CSV"**
2. A `filter-config-export-YYYY-MM-DD.csv` file downloads

CSV is handy if you work with automated tools or want to open the file in a text editor.

> **Tip:** Before bulk changes — export as a backup. If something goes wrong — import the old file back in "Overwrite" mode.

---

## 12. Diagnostics and maintenance

Over time, issues can appear: you deleted an attribute from WooCommerce but it's still referenced in filters; you deleted a category but its filter settings remain in the database. The **"Diagnostics"** tab helps detect and fix such problems.

### Statistics

At the top of the tab there are 4 cards:

| Card                    | What it shows                                            |
| ----------------------- | -------------------------------------------------------- |
| **Total categories**    | How many product categories are in your store            |
| **Configured manually** | How many categories have their own filter settings       |
| **Using inheritance**   | How many categories inherit filters from a parent        |
| **Total filters**       | The total number of configured filters across categories |

The numbers update after you click **"Run check"**.

### Problem scanner

Click **"🔍 Run check"**. The plugin checks:

#### Broken filters

A filter is considered "broken" if it references a WooCommerce attribute that no longer exists. For example: you deleted the `pa_series` attribute from WooCommerce, but it's still in the filters.

Such a filter won't be shown to customers (no attribute — no options).

**What to do:** open the problematic category, find the filter with that attribute and:

- Delete it (if the attribute is no longer needed)
- Or change the attribute to a valid one

#### Orphaned configurations

An "orphaned" record is a set of filter settings for a category that no longer exists. For example: you deleted the "Old reels" category from WooCommerce, but its filter settings remained in the database.

They don't affect the site, but they take up space in the database.

**What to do:** click **"🗑 Delete configs for non-existent categories"**.

> **Warning:** this action is irreversible. Before doing it, make sure the list of orphaned records contains nothing you need.

---

## 13. Webhook — automatic site refresh

### What a webhook is and why you need it

Your site may cache (store copies of) catalog pages for fast loading. When you change filters — the cache doesn't refresh automatically. Visitors would see the old filters.

A **webhook** is a way to tell the site: "Hey, the filters changed — refresh the cache!". Every time you save filters, the plugin automatically sends a signal to the site.

### Configuration

1. Go to the **"Settings"** tab
2. Fill in the fields:

**Webhook URL:** the address the plugin sends the notification to.

For a Next.js site it usually looks like this:

```
https://your-site.com/api/revalidate
```

Check the correct address with the site's developer.

**Secret key:** any random string that protects the webhook from outsiders. For example: `MyS3cr3tK3y_2024`. The same key must be configured on the site's side.

**Log requests:** if enabled — every webhook request is written to a log. Useful for debugging, but not needed in normal operation.

3. Click **"Test webhook"** — the plugin sends a test request and shows the result
4. Click **"Save"**

### When the webhook fires

- On a manual filter save for a category
- On import (for each updated category)
- On copying settings
- On restoring from history

### What to do if the webhook doesn't work

1. Check the URL — it must be reachable from the internet (not `localhost`)
2. Check the Secret key — it must match the setting on the site
3. Click "Test webhook" — the response will contain a detailed error

---

## 14. Plugin settings

The **"Settings"** tab has several sections:

### Webhook (described in section 13)

### Webhook log

If logging is enabled — the most recent webhook requests are shown here: time, category, response status.

### Advanced

#### "Delete all data when the plugin is removed"

Default: **disabled** (data is kept).

If you check the box — when the plugin is deleted from WordPress, all data is removed:

- The filter settings table
- The change history table
- All plugin settings (webhook, etc.)

**When to enable:** if you want to completely remove all traces of the plugin on uninstall.

**When NOT to enable:** if you plan to reinstall the plugin later — the data is kept and everything is restored automatically on the next install.

> **Warning:** if this option is enabled and you delete the plugin — the data cannot be recovered. Make an [export](#11-exporting-current-settings) first.

---

## 15. Common questions and issues

### Filters don't show on the site

Check step by step:

1. **Is the filter enabled?** Open the category → make sure the toggle is green
2. **Is the attribute specified?** The "WC Attribute" field must be filled in
3. **Is the attribute correct?** Open the **Preview** — if it's empty, the attribute is wrong or the products have no values
4. **Are the products filled in?** Open any product from the category → check that the attributes are filled in
5. **Is the cache stale?** If a webhook is configured — check that it works. Or clear the site cache manually

### Import can't find a category

Possible causes:

- **Extra spaces** at the start or end of the name. Remove them.
- **Typo.** Copy the name exactly from the "WooCommerce Categories" sheet in the template.
- **The category doesn't exist.** Create the category in WooCommerce first, then import.

### Import can't find an attribute

- Copy the slug from the "WooCommerce Attributes" sheet in the template
- Make sure the attribute is created under **WooCommerce → Products → Attributes**
- Remember: the "Brand" attribute with slug `brand` is stored in WooCommerce as `pa_brand` (the `pa_` prefix is added automatically)

### After import the category shows "Skipped"

You chose "Skip" mode and this category already had settings. To replace them — choose **"Overwrite"** mode.

### The preview shows empty filters

The filters are configured correctly, but the products in this category have no values for the corresponding attributes. Solution: open each product → the "Attributes" tab → add values.

### After saving, the site still shows old filters

If a webhook is configured — wait 30–60 seconds, the cache refreshes automatically. If no webhook is configured — clear the site cache manually. Ask your developer how to do it for your site.

### The "Save" button is inactive

Try:

1. Refreshing the page (F5)
2. Checking that the required fields ("Name" and "WC Attribute") are filled in for all filters

### The version I need isn't in the history

History keeps up to 20 versions per category and deletes entries older than 90 days. If a version wasn't saved — it can't be restored. Make regular exports as a backup.

### What to do if the plugin "hangs" or doesn't respond

1. Refresh the page (F5)
2. Clear the browser cache (Ctrl+Shift+Del → "Cached images and files")
3. Try a different browser
4. Check the browser console (F12) for errors

---

## 16. Glossary

**WooCommerce attribute** — a product characteristic created under "WooCommerce → Products → Attributes". For example: Brand, Color, Material. An attribute slug is its technical name (usually starts with `pa_`).

**Slug** — a short technical name used in URLs and code. Written in lowercase Latin letters. Example: the "Reels" category can have the slug `reels`.

**Cache** — a stored copy of a site page for fast loading. When you change filters — the cache needs to be cleared so visitors see the changes.

**Webhook** — an automatic HTTP notification from the plugin to the site on every filter change.

**Inheritance** — automatically receiving filter settings from a parent category without manual configuration.

**Dry run** — a trial import run with no real changes. Helps validate the file before a real import.

**Orphaned record** — filter settings for a category that no longer exists in WooCommerce.

**Broken filter** — a filter that references a WooCommerce attribute that no longer exists.

**XLSX** — the Microsoft Excel file format. Opens in Excel, LibreOffice Calc, Google Sheets.

**CSV** — a simple text table format. Columns separated by `;` or `,`. Opens in any editor.

---

_Documentation version: 2.3.0 | Last updated: 2026-04-18_
