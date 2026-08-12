<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cfc_render_admin_page(): void {
    $tab         = sanitize_key( wp_unslash( $_GET['tab'] ?? 'filters' ) );
    $selected    = absint( $_GET['cat'] ?? 0 );
    $categories  = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
    ] );
    if ( is_wp_error( $categories ) ) $categories = [];
    ?>
    <div class="wrap cfc-wrap">
        <h1>Catalog Filters</h1>

        <nav class="nav-tab-wrapper">
            <a href="?page=catalog-filter-config&tab=filters"
               class="nav-tab <?php echo $tab === 'filters' ? 'nav-tab-active' : ''; ?>">
                Filter setup
            </a>
            <a href="?page=catalog-filter-config&tab=import"
               class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>">
                Import / Export
            </a>
            <a href="?page=catalog-filter-config&tab=diagnostics"
               class="nav-tab <?php echo $tab === 'diagnostics' ? 'nav-tab-active' : ''; ?>">
                🔍 Diagnostics
            </a>
            <a href="?page=catalog-filter-config&tab=settings"
               class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                Settings
            </a>
            <a href="?page=catalog-filter-config&tab=guide"
               class="nav-tab <?php echo $tab === 'guide' ? 'nav-tab-active' : ''; ?>">
                📖 Guide
            </a>
        </nav>

        <div class="cfc-tab-content">
            <?php if ( $tab === 'filters' ): ?>
                <?php cfc_render_tab_filters( $categories, $selected ); ?>
            <?php elseif ( $tab === 'import' ): ?>
                <?php cfc_render_tab_import(); ?>
            <?php elseif ( $tab === 'diagnostics' ): ?>
                <?php cfc_render_tab_diagnostics(); ?>
            <?php elseif ( $tab === 'settings' ): ?>
                <?php cfc_render_tab_settings(); ?>
            <?php elseif ( $tab === 'guide' ): ?>
                <?php cfc_render_tab_guide(); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter row template for JS -->
    <script type="text/html" id="cfc-row-template">
        <?php cfc_render_filter_row( [], '__INDEX__' ); ?>
    </script>
    <?php
}


function cfc_render_tab_filters( array $categories, int $selected ): void {
    ?>
    <div class="cfc-layout">

        <!-- Left panel: category tree -->
        <aside class="cfc-sidebar">
            <div class="cfc-panel">
                <div class="cfc-panel-header">
                    <span>Categories</span>
                    <span class="cfc-cat-count"><?php echo count( $categories ); ?></span>
                </div>

                <!-- Category search -->
                <div class="cfc-cat-search-wrap">
                    <input type="search" id="cfc-cat-search"
                           placeholder="Search category..." autocomplete="off" />
                </div>

                <div class="cfc-panel-body cfc-cat-panel-body">
                    <?php cfc_render_category_tree( $categories, 0, $selected ); ?>
                </div>
            </div>
        </aside>

        <!-- Right panel: editor -->
        <main class="cfc-content">
            <?php if ( $selected ): ?>

                <?php
                $term      = get_term( $selected, 'product_cat' );
                $own       = cfc_get_own_filters( $selected );
                $filters   = cfc_get_category_filters( $selected );
                $inherited = empty( $own ) && ! empty( $filters );
                $history   = cfc_get_history( $selected );
                ?>

                <?php if ( $inherited ): ?>
                    <div class="notice notice-info inline cfc-notice-inherited">
                        <p>
                            Inherited settings are shown.
                            Any change will create <strong>custom</strong> settings for this category.
                            <a href="#" id="cfc-reset-btn" data-category="<?php echo esc_attr( $selected ); ?>">
                                Reset to inheritance
                            </a>
                        </p>
                    </div>
                <?php elseif ( $own ): ?>
                    <div class="cfc-notice-own">
                        <span class="cfc-own-badge">Custom settings</span>
                        <a href="#" id="cfc-reset-btn" data-category="<?php echo esc_attr( $selected ); ?>">
                            Reset to inheritance
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Main editor panel -->
                <div class="cfc-panel">
                    <div class="cfc-panel-header">
                        <span>
                            Filters:
                            <strong><?php echo esc_html( $term->name ); ?></strong>
                            <span class="cfc-product-count"
                                  title="Products in category">
                                <?php echo esc_html( $term->count ); ?> products
                            </span>
                        </span>
                        <div class="cfc-toolbar">
                            <!-- Bulk actions -->
                            <div class="cfc-bulk-actions">
                                <button class="button cfc-btn-bulk-enable" title="Enable all">
                                    Enable all
                                </button>
                                <button class="button cfc-btn-bulk-disable" title="Disable all">
                                    Disable all
                                </button>
                            </div>

                            <!-- Copy to other categories -->
                            <button class="button" id="cfc-copy-btn">
                                Copy to...
                            </button>

                            <!-- History -->
                            <?php if ( $history ): ?>
                                <button class="button" id="cfc-history-btn">
                                    History (<?php echo count( $history ); ?>)
                                </button>
                            <?php endif; ?>

                            <!-- Preview -->
                            <button class="button" id="cfc-preview-btn"
                                    data-category="<?php echo esc_attr( $selected ); ?>">
                                Preview
                            </button>

                            <button class="button button-primary" id="cfc-save"
                                    data-category="<?php echo esc_attr( $selected ); ?>">
                                Save
                            </button>
                        </div>
                    </div>

                    <div class="cfc-panel-body">
                        <!-- Column headers -->
                        <div class="cfc-filters-header">
                            <span></span>
                            <span>Name</span>
                            <span>WooCommerce attribute</span>
                            <span>Type</span>
                            <span>Range</span>
                            <span>Min. products</span>
                            <span>Order</span>
                            <span>On</span>
                            <span></span>
                        </div>

                        <!-- Filter list with drag-and-drop -->
                        <div id="cfc-filters-list">
                            <?php foreach ( $filters as $i => $filter ): ?>
                                <?php cfc_render_filter_row( $filter, $i ); ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ( empty( $filters ) ): ?>
                            <div class="cfc-empty" id="cfc-empty-state">
                                <p>No filters configured.</p>
                                <p>Click "+ Add filter" or import from xlsx.</p>
                            </div>
                        <?php endif; ?>

                        <button class="button cfc-add-btn" id="cfc-add-filter">
                            + Add filter
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <div class="cfc-panel cfc-empty-state-panel">
                    <div class="cfc-panel-body cfc-welcome">
                        <div class="cfc-welcome-icon">⚙</div>
                        <h2>Select a category</h2>
                        <p>Click a category in the left panel to configure filters.</p>
                        <p>Child categories automatically <strong>inherit</strong> the parent's
                           settings unless overridden.</p>
                    </div>
                </div>
            <?php endif; ?>
        </main>

    </div>

    <!-- Modals -->
    <?php cfc_render_modals( $selected, $categories ); ?>
    <?php
}


function cfc_render_filter_row( array $filter, $index ): void {
    $type         = $filter['type']         ?? 'checkbox';
    $name         = $filter['display_name'] ?? '';
    $attr         = $filter['attr_slug']    ?? '';
    $min          = $filter['range_min']    ?? 0;
    $max          = $filter['range_max']    ?? 9999;
    $order        = $filter['order']        ?? 10;
    $enabled      = $filter['enabled']      ?? true;
    $min_products = $filter['min_products'] ?? 0;
    ?>
    <div class="cfc-filter-row <?php echo $enabled ? '' : 'is-disabled'; ?>"
         data-index="<?php echo esc_attr( $index ); ?>">

        <span class="cfc-drag-handle" title="Drag">⠿</span>

        <input type="text"
               class="cfc-field-name"
               name="filters[<?php echo esc_attr( $index ); ?>][display_name]"
               value="<?php echo esc_attr( $name ); ?>"
               placeholder="Filter name" />

        <select class="cfc-field-attr" name="filters[<?php echo esc_attr( $index ); ?>][attr_slug]">
            <option value="">— select attribute —</option>
            <option value="price" <?php selected( $attr, 'price' ); ?>>
                Price (built-in)
            </option>
            <optgroup label="WooCommerce attributes">
                <?php foreach ( cfc_get_wc_attributes() as $a ): ?>
                    <option value="<?php echo esc_attr( $a['slug'] ); ?>"
                            <?php selected( $attr, $a['slug'] ); ?>>
                        <?php echo esc_html( $a['label'] ); ?>
                        (<?php echo esc_html( $a['slug'] ); ?>)
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>

        <select class="cfc-field-type" name="filters[<?php echo esc_attr( $index ); ?>][type]">
            <option value="checkbox" <?php selected( $type, 'checkbox' ); ?>>Checkboxes</option>
            <option value="radio"    <?php selected( $type, 'radio' ); ?>>Radio buttons</option>
            <option value="range"    <?php selected( $type, 'range' ); ?>>Slider</option>
            <option value="toggle"   <?php selected( $type, 'toggle' ); ?>>Toggle</option>
            <option value="color"    <?php selected( $type, 'color' ); ?>>Color</option>
        </select>

        <div class="cfc-range-fields"
             style="<?php echo $type === 'range' ? '' : 'visibility:hidden'; ?>">
            <input type="number" class="cfc-field-min"
                   name="filters[<?php echo esc_attr( $index ); ?>][range_min]"
                   value="<?php echo esc_attr( $min ); ?>" placeholder="Min" />
            <span>—</span>
            <input type="number" class="cfc-field-max"
                   name="filters[<?php echo esc_attr( $index ); ?>][range_max]"
                   value="<?php echo esc_attr( $max ); ?>" placeholder="Max" />
        </div>

        <input type="number" class="cfc-field-min-products"
               name="filters[<?php echo esc_attr( $index ); ?>][min_products]"
               value="<?php echo esc_attr( $min_products ); ?>"
               placeholder="0" min="0"
               title="Hide if fewer products than..." />

        <input type="number" class="cfc-field-order"
               name="filters[<?php echo esc_attr( $index ); ?>][order]"
               value="<?php echo esc_attr( $order ); ?>"
               min="1" max="999" />

        <label class="cfc-toggle" title="<?php echo $enabled ? 'Enabled' : 'Disabled'; ?>">
            <input type="checkbox" class="cfc-field-enabled"
                   name="filters[<?php echo esc_attr( $index ); ?>][enabled]"
                   value="1" <?php checked( $enabled, true ); ?> />
            <span class="cfc-toggle-slider"></span>
        </label>

        <button type="button" class="cfc-remove-row" title="Delete">✕</button>
    </div>
    <?php
}


function cfc_render_category_tree( array $terms, int $parent, int $selected ): void {
    $children = array_filter( $terms, fn( $t ) => $t->parent === $parent );
    if ( empty( $children ) ) return;
    ?>
    <ul class="cfc-cat-tree <?php echo $parent === 0 ? 'is-root' : ''; ?>">
        <?php foreach ( $children as $term ):
            $has_own      = cfc_has_own_filters( $term->term_id );
            $has_children = (bool) array_filter( $terms, fn( $t ) => $t->parent === $term->term_id );
            ?>
            <li class="cfc-cat-item <?php echo $has_children ? 'has-children' : ''; ?>
                        <?php echo $term->term_id === $selected ? 'is-selected' : ''; ?>">

                <?php if ( $has_children ): ?>
                    <button class="cfc-cat-toggle" type="button" aria-label="Expand">
                        <svg width="12" height="12" viewBox="0 0 12 12">
                            <path d="M4 2l4 4-4 4" stroke="currentColor" stroke-width="1.5"
                                  fill="none" stroke-linecap="round"/>
                        </svg>
                    </button>
                <?php else: ?>
                    <span class="cfc-cat-leaf"></span>
                <?php endif; ?>

                <a href="<?php echo esc_url( add_query_arg( [
                    'page' => 'catalog-filter-config',
                    'tab'  => 'filters',
                    'cat'  => $term->term_id,
                ] ) ); ?>"
                   class="cfc-cat-link <?php echo $term->term_id === $selected ? 'is-active' : ''; ?>"
                   data-id="<?php echo $term->term_id; ?>"
                   data-name="<?php echo esc_attr( $term->name ); ?>">

                    <span class="cfc-cat-name"><?php echo esc_html( $term->name ); ?></span>

                    <?php if ( $has_own ): ?>
                        <span class="cfc-configured-dot" title="Custom filters configured">●</span>
                    <?php endif; ?>

                    <span class="cfc-cat-count-badge"><?php echo esc_html( $term->count ); ?></span>
                </a>

                <?php if ( $has_children ): ?>
                    <?php cfc_render_category_tree( $terms, $term->term_id, $selected ); ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}


function cfc_render_tab_import(): void {
    ?>
    <!-- ── Step 1: Download template / Export ──────────────────────────── -->
    <div class="cfc-panel">
        <div class="cfc-panel-header">1. Prepare the file</div>
        <div class="cfc-panel-body">
            <p class="description">
                Download the template, fill it in and upload it back —
                filters apply automatically. Supported formats:
                <strong>XLSX</strong> and <strong>CSV</strong>.
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <button class="button" id="cfc-download-template">
                    📥 Download Excel template
                </button>
                <button class="button" id="cfc-export-btn">
                    📤 Export to XLSX
                </button>
                <button class="button" id="cfc-export-csv-btn">
                    📄 Export to CSV
                </button>
            </div>
            <p class="description" style="margin-top:10px">
                The template contains <strong>examples</strong>, a list of
                <strong>all categories</strong> and <strong>attributes</strong>
                of your store on separate sheets.
            </p>
        </div>
    </div>

    <!-- ── Step 2: Upload the file ─────────────────────────────────────── -->
    <div class="cfc-panel" id="cfc-import-step-upload">
        <div class="cfc-panel-header">2. Upload the file</div>
        <div class="cfc-panel-body">

            <!-- Drag-drop zone -->
            <div class="cfc-dropzone" id="cfc-dropzone">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <p>Drag a file here or
                    <label for="cfc-xlsx-file" class="cfc-dropzone__link">select a file</label>
                </p>
                <p class="cfc-dropzone__hint">XLSX, XLS or CSV · max 10 MB</p>
                <input type="file" id="cfc-xlsx-file" accept=".xlsx,.xls,.csv" />
            </div>

            <div id="cfc-file-selected" style="display:none" class="cfc-file-selected">
                <span class="cfc-file-icon">📄</span>
                <span id="cfc-import-file-info"></span>
                <button type="button" class="cfc-file-clear" id="cfc-file-clear">✕</button>
            </div>

            <div class="cfc-form-row" style="margin-top:16px">
                <label><strong>If the category is already configured:</strong></label>
                <select id="cfc-import-mode">
                    <option value="skip">Skip (don't overwrite)</option>
                    <option value="overwrite">Overwrite</option>
                </select>
            </div>

            <div class="cfc-form-row">
                <button class="button button-primary" id="cfc-preview-import-btn" disabled>
                    Check file →
                </button>
            </div>
        </div>
    </div>

    <!-- ── Step 3: Preview ─────────────────────────────────────────────── -->
    <div class="cfc-panel" id="cfc-import-step-preview" style="display:none">
        <div class="cfc-panel-header">
            3. Review the data before importing
            <button type="button" class="button" id="cfc-preview-back-btn" style="font-size:12px">
                ← Back
            </button>
        </div>
        <div class="cfc-panel-body">
            <p class="description">
                The first rows of the file. Make sure the data is recognized correctly.
            </p>
            <div id="cfc-preview-table-wrap"></div>
            <div id="cfc-preview-warnings"></div>
            <p id="cfc-preview-total" class="description" style="margin-top:8px"></p>
            <div class="cfc-form-row" style="margin-top:16px">
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                    <input type="checkbox" id="cfc-dry-run" />
                    <span>
                        <strong>Test mode</strong> — validate only, save nothing
                    </span>
                </label>
                <button class="button button-primary" id="cfc-import-btn">
                    Import all rows
                </button>
                <span id="cfc-import-status" style="margin-left:12px"></span>
            </div>
            <div id="cfc-import-progress" style="display:none;margin-top:12px">
                <div class="cfc-progress-bar"><div class="cfc-progress-fill"></div></div>
                <p id="cfc-import-log"></p>
            </div>
        </div>
    </div>

    <!-- ── Step 4: Result ──────────────────────────────────────────────── -->
    <div id="cfc-import-results" style="display:none" class="cfc-panel">
        <div class="cfc-panel-header">Import results</div>
        <div class="cfc-panel-body">
            <table class="widefat striped">
                <tbody id="cfc-import-results-body"></tbody>
            </table>
            <div style="margin-top:12px">
                <button class="button" id="cfc-import-reset-btn">← Import more</button>
            </div>
        </div>
    </div>

    <!-- ── Format reference ────────────────────────────────────────────── -->
    <div class="cfc-panel">
        <div class="cfc-panel-header">File format (XLSX / CSV)</div>
        <div class="cfc-panel-body">
            <table class="widefat striped" style="max-width:820px">
                <thead>
                    <tr><th>Column</th><th>Required</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Category</strong></td><td>✅</td><td>WooCommerce category name</td></tr>
                    <tr><td><strong>Filter</strong></td><td>✅</td><td>Customer-facing filter name (e.g. "Brand", "Price")</td></tr>
                    <tr><td><strong>Type</strong></td><td>—</td><td>checkbox / range / radio / toggle / color (default: checkbox)</td></tr>
                    <tr><td><strong>WC Attribute</strong></td><td>—</td><td>WooCommerce attribute slug (pa_brand, price). If empty — detected automatically</td></tr>
                    <tr><td><strong>Min / Max</strong></td><td>—</td><td>Range for the range type</td></tr>
                    <tr><td><strong>Order</strong></td><td>—</td><td>Display order (1, 2, 3…)</td></tr>
                    <tr><td><strong>Enabled</strong></td><td>—</td><td>Yes / 1 / yes (default: Yes)</td></tr>
                    <tr><td><strong>Min. products</strong></td><td>—</td><td>Hide if fewer products (default: 0)</td></tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:12px">
                💡 <strong>CSV:</strong> the <code>;</code> or <code>,</code> delimiter is detected automatically.
            </p>
        </div>
    </div>
    <?php
}


function cfc_render_tab_diagnostics(): void {
    ?>
    <!-- Statistics cards -->
    <div class="cfc-stat-grid" id="cfc-stat-grid">
        <div class="cfc-stat-card">
            <div class="cfc-stat-value" id="stat-total-categories">—</div>
            <div class="cfc-stat-label">Total categories</div>
        </div>
        <div class="cfc-stat-card">
            <div class="cfc-stat-value" id="stat-configured">—</div>
            <div class="cfc-stat-label">Configured manually</div>
        </div>
        <div class="cfc-stat-card">
            <div class="cfc-stat-value" id="stat-inherited">—</div>
            <div class="cfc-stat-label">Using inheritance</div>
        </div>
        <div class="cfc-stat-card">
            <div class="cfc-stat-value" id="stat-total-filters">—</div>
            <div class="cfc-stat-label">Total filters</div>
        </div>
    </div>

    <!-- Problem scanner -->
    <div class="cfc-panel">
        <div class="cfc-panel-header">
            Problem scanner
            <button class="button button-primary" id="cfc-run-diagnostics">
                🔍 Run check
            </button>
        </div>
        <div class="cfc-panel-body">
            <p class="description">
                Checks all configured filters and detects:
                <strong>deleted WooCommerce attributes</strong>,
                <strong>configs for non-existent categories</strong>.
            </p>
            <div id="cfc-diagnostics-result" style="display:none">

                <!-- Broken filters -->
                <div id="cfc-broken-wrap">
                    <h3 id="cfc-broken-title"></h3>
                    <div id="cfc-broken-list"></div>
                </div>

                <!-- Orphans -->
                <div id="cfc-orphaned-wrap" style="margin-top:20px">
                    <h3 id="cfc-orphaned-title"></h3>
                    <div id="cfc-orphaned-list"></div>
                    <div id="cfc-fix-orphaned-wrap" style="display:none;margin-top:10px">
                        <button class="button" id="cfc-fix-orphaned">
                            🗑 Delete configs for non-existent categories
                        </button>
                        <span id="cfc-fix-status" style="margin-left:10px"></span>
                    </div>
                </div>

                <!-- All OK -->
                <div id="cfc-ok-notice" style="display:none">
                    <div class="notice notice-success inline" style="margin:0">
                        <p>✅ No problems found. All filters are configured correctly.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}


function cfc_render_tab_settings(): void {
    $webhook_url      = get_option( 'cfc_webhook_url', '' );
    $webhook_secret   = get_option( 'cfc_webhook_secret', '' );
    $webhook_log      = get_option( 'cfc_webhook_log', false );
    $log_entries      = get_option( 'cfc_webhook_log_entries', [] );
    $delete_on_uninst = get_option( 'cfc_delete_on_uninstall', false );
    ?>
    <div class="cfc-panel">
        <div class="cfc-panel-header">Webhook → Next.js revalidate</div>
        <div class="cfc-panel-body">

            <p class="description">
                When filters are saved, the plugin sends a POST request to this URL.
                Next.js should expose a <code>/api/revalidate</code> endpoint that
                invalidates the catalog pages cache.
            </p>

            <table class="form-table">
                <tr>
                    <th>Webhook URL</th>
                    <td>
                        <input type="url" id="cfc-webhook-url"
                               value="<?php echo esc_attr( $webhook_url ); ?>"
                               class="regular-text"
                               placeholder="https://your-nextjs.com/api/revalidate" />
                    </td>
                </tr>
                <tr>
                    <th>Secret key</th>
                    <td>
                        <input type="text" id="cfc-webhook-secret"
                               value="<?php echo esc_attr( $webhook_secret ); ?>"
                               class="regular-text"
                               placeholder="Random string for signature verification" />
                        <p class="description">
                            Signature: <code>HMAC-SHA256(payload, secret)</code>
                            in the <code>X-CFC-Signature</code> header
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Log requests</th>
                    <td>
                        <label>
                            <input type="checkbox" id="cfc-webhook-log"
                                   <?php checked( $webhook_log ); ?> />
                            Keep a history of deliveries
                        </label>
                    </td>
                </tr>
            </table>

            <div style="margin-top:16px;display:flex;gap:8px">
                <button class="button button-primary" id="cfc-save-webhook">
                    Save settings
                </button>
                <button class="button" id="cfc-test-webhook">
                    Test webhook
                </button>
                <span id="cfc-webhook-status"></span>
            </div>

            <?php if ( $log_entries ): ?>
                <h4 style="margin-top:20px">Recent deliveries</h4>
                <table class="widefat striped" style="max-width:600px">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Category</th>
                            <th>URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log_entries as $entry ): ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ); ?></td>
                                <td><?php echo esc_html( $entry['category'] ); ?></td>
                                <td style="font-size:12px;word-break:break-all">
                                    <?php echo esc_html( $entry['url'] ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
    </div>

    <div class="cfc-panel">
        <div class="cfc-panel-header">Advanced</div>
        <div class="cfc-panel-body">
            <table class="form-table">
                <tr>
                    <th>On plugin removal</th>
                    <td>
                        <label>
                            <input type="checkbox" id="cfc-delete-on-uninstall"
                                   <?php checked( $delete_on_uninst ); ?> />
                            <strong>Delete all plugin data</strong> (DB tables, settings, caches)
                        </label>
                        <p class="description" style="margin-top:6px;color:#d63638">
                            ⚠ If disabled — filters persist across uninstall and reinstall.
                            If enabled — data is deleted permanently.
                        </p>
                    </td>
                </tr>
            </table>
            <div style="margin-top:12px">
                <button class="button button-primary" id="cfc-save-advanced">
                    Save
                </button>
                <span id="cfc-advanced-status" style="margin-left:10px"></span>
            </div>
        </div>
    </div>

    <div class="cfc-panel">
        <div class="cfc-panel-header">REST API endpoints</div>
        <div class="cfc-panel-body">
            <p>Endpoints available to Next.js:</p>
            <table class="widefat" style="max-width:700px">
                <thead><tr><th>Method</th><th>URL</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_html( rest_url( 'catalog/v1/filters/{slug}' ) ); ?></code></td>
                        <td>Filters for a single category</td>
                    </tr>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_html( rest_url( 'catalog/v1/filters/batch?slugs=a,b,c' ) ); ?></code></td>
                        <td>Several categories at once</td>
                    </tr>
                    <tr>
                        <td><code>POST</code></td>
                        <td><code><?php echo esc_html( rest_url( 'catalog/v1/filters/batch' ) ); ?></code></td>
                        <td>Batch (body: <code>{"slugs":["a","b"]}</code>)</td>
                    </tr>
                    <tr>
                        <td><code>POST</code></td>
                        <td><code><?php echo esc_html( rest_url( 'catalog/v1/query' ) ); ?></code></td>
                        <td>GraphQL-style query</td>
                    </tr>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_html( rest_url( 'catalog/v1/attributes' ) ); ?></code></td>
                        <td>All WooCommerce attributes</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}


function cfc_render_modals( int $selected, array $categories ): void {
    ?>
    <!-- Modal: Copy to other categories -->
    <div id="cfc-copy-modal" class="cfc-modal" style="display:none">
        <div class="cfc-modal-backdrop"></div>
        <div class="cfc-modal-box">
            <div class="cfc-modal-header">
                Copy settings to categories
                <button class="cfc-modal-close">✕</button>
            </div>
            <div class="cfc-modal-body">
                <p class="description">Select categories to copy the current settings to:</p>

                <div class="cfc-modal-search">
                    <input type="search" id="cfc-copy-search" placeholder="Find category..." />
                </div>

                <div class="cfc-copy-cats-list">
                    <?php foreach ( $categories as $cat ):
                        if ( $cat->term_id === $selected ) continue;
                        $indent = str_repeat( '&nbsp;&nbsp;&nbsp;', cfc_get_category_depth( $cat->term_id, $categories ) );
                        ?>
                        <label class="cfc-copy-cat-item">
                            <input type="checkbox" value="<?php echo $cat->term_id; ?>"
                                   class="cfc-copy-cat-check" />
                            <?php echo $indent; ?>
                            <?php echo esc_html( $cat->name ); ?>
                            <span class="cfc-copy-configured"
                                  <?php echo cfc_has_own_filters( $cat->term_id ) ? '' : 'style="display:none"'; ?>
                                  title="Already configured">●</span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <label class="cfc-copy-overwrite">
                    <input type="checkbox" id="cfc-copy-overwrite" />
                    Overwrite existing settings
                </label>
            </div>
            <div class="cfc-modal-footer">
                <button class="button" id="cfc-copy-select-all">Select all</button>
                <button class="button" id="cfc-copy-select-none">Deselect all</button>
                <div style="flex:1"></div>
                <button class="button cfc-modal-close">Cancel</button>
                <button class="button button-primary" id="cfc-copy-confirm"
                        data-from="<?php echo esc_attr( $selected ); ?>">
                    Copy
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Change history -->
    <div id="cfc-history-modal" class="cfc-modal" style="display:none">
        <div class="cfc-modal-backdrop"></div>
        <div class="cfc-modal-box">
            <div class="cfc-modal-header">
                Change history
                <button class="cfc-modal-close">✕</button>
            </div>
            <div class="cfc-modal-body">
                <div id="cfc-history-list">
                    <p>Loading...</p>
                </div>
            </div>
            <div class="cfc-modal-footer">
                <button class="button cfc-modal-close">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal: Preview -->
    <div id="cfc-preview-modal" class="cfc-modal cfc-modal-wide" style="display:none">
        <div class="cfc-modal-backdrop"></div>
        <div class="cfc-modal-box">
            <div class="cfc-modal-header">
                <span id="cfc-preview-title">Filter preview</span>
                <button class="cfc-modal-close">✕</button>
            </div>
            <div class="cfc-modal-body">
                <div id="cfc-preview-content">
                    <div class="cfc-preview-loading">
                        <span class="spinner is-active"></span>
                        Loading data from WooCommerce...
                    </div>
                </div>
            </div>
            <div class="cfc-modal-footer">
                <span class="description" id="cfc-preview-info"></span>
                <div style="flex:1"></div>
                <button class="button cfc-modal-close">Close</button>
            </div>
        </div>
    </div>
    <?php
}


function cfc_render_tab_guide(): void {
    $guide_path = CFC_DIR . 'docs/USER_GUIDE.md';
    $guide_url  = CFC_URL . 'docs/USER_GUIDE.md';

    if ( ! file_exists( $guide_path ) ) {
        echo '<div class="notice notice-error"><p>Documentation file not found.</p></div>';
        return;
    }

    $markdown = file_get_contents( $guide_path );
    $html     = cfc_markdown_to_html( $markdown );
    ?>
    <div class="cfc-panel">
        <div class="cfc-panel-header">
            <span>📖 User guide</span>
            <a href="<?php echo esc_url( $guide_url ); ?>"
               class="button"
               download="USER_GUIDE.md"
               title="Download as a Markdown file">
                ⬇ Download .md
            </a>
        </div>
        <div class="cfc-panel-body cfc-guide-content">
            <?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized in cfc_markdown_to_html ?>
        </div>
    </div>
    <?php
}

function cfc_markdown_to_html( string $md ): string {
    $md = str_replace( [ "\r\n", "\r" ], "\n", $md );

    $md = esc_html( $md );

    $md = preg_replace_callback( '/```(?:\w*)\n(.*?)\n```/s', function ( $m ) {
        return '<pre class="cfc-code-block"><code>' . $m[1] . '</code></pre>';
    }, $md );

    $md = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $md );

    $md = preg_replace( '/^#### (.+)$/m', '<h4>$1</h4>', $md );
    $md = preg_replace( '/^### (.+)$/m',  '<h3>$1</h3>', $md );
    $md = preg_replace( '/^## (.+)$/m',   '<h2>$1</h2>', $md );
    $md = preg_replace( '/^# (.+)$/m',    '<h1>$1</h1>', $md );

    $md = preg_replace( '/^---+$/m', '<hr />', $md );

    $md = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $md );
    $md = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md );
    $md = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $md );

    $md = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md );

    $md = preg_replace( '/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $md );

    $lines  = explode( "\n", $md );
    $output = [];
    $in_table = false;

    foreach ( $lines as $line ) {
        $trimmed = trim( $line );

        if ( preg_match( '/^\|[\s\-:|]+\|$/', $trimmed ) ) {
            continue; // skip
        }

        if ( preg_match( '/^\|(.+)\|$/', $trimmed ) ) {
            $cells = array_map( 'trim', explode( '|', trim( $trimmed, '|' ) ) );

            if ( ! $in_table ) {
                $output[] = '<table class="widefat striped cfc-guide-table"><thead><tr>';
                foreach ( $cells as $cell ) {
                    $output[] = '<th>' . $cell . '</th>';
                }
                $output[] = '</tr></thead><tbody>';
                $in_table = true;
            } else {
                $output[] = '<tr>';
                foreach ( $cells as $cell ) {
                    $output[] = '<td>' . $cell . '</td>';
                }
                $output[] = '</tr>';
            }
            continue;
        }

        if ( $in_table ) {
            $output[] = '</tbody></table>';
            $in_table = false;
        }

        if ( preg_match( '/^(\s*)- (.+)$/', $line, $m ) ) {
            $output[] = '<li>' . trim( $m[2] ) . '</li>';
            continue;
        }

        if ( preg_match( '/^(\s*)\d+\. (.+)$/', $line, $m ) ) {
            $output[] = '<li>' . trim( $m[2] ) . '</li>';
            continue;
        }

        $output[] = $line;
    }

    if ( $in_table ) {
        $output[] = '</tbody></table>';
    }

    $html = implode( "\n", $output );

    $html = preg_replace( '/(<li>.*?<\/li>\n?)+/s', '<ul>$0</ul>', $html );

    $html = preg_replace( '/\n{2,}/', '</p><p>', $html );
    $html = '<p>' . $html . '</p>';

    $html = preg_replace( '/<p>\s*<\/p>/', '', $html );

    $blocks = 'h[1-6]|hr|table|thead|tbody|tr|th|td|pre|blockquote|ul|li';
    $html = preg_replace( '/<p>\s*(<(?:' . $blocks . ')[\s>])/i', '$1', $html );
    $html = preg_replace( '/(<\/(?:' . $blocks . ')>)\s*<\/p>/i', '$1', $html );

    return $html;
}


function cfc_get_category_depth( int $term_id, array $all_terms, int $depth = 0 ): int {
    foreach ( $all_terms as $t ) {
        if ( $t->term_id === $term_id ) {
            if ( $t->parent ) {
                return cfc_get_category_depth( $t->parent, $all_terms, $depth + 1 );
            }
            return $depth;
        }
    }
    return $depth;
}
