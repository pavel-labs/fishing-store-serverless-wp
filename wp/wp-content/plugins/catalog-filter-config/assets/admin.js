/* global CFC, Sortable */
jQuery(($) => {
  const { nonce, ajax_url, wc_attrs } = window.CFC;

  // ─── Drag-and-drop ────────────────────────────────────────────────────────
  const filterList = document.getElementById("cfc-filters-list");
  if (filterList) {
    Sortable.create(filterList, {
      handle: ".cfc-drag-handle",
      animation: 150,
      ghostClass: "cfc-row-ghost",
      onEnd() {
        reindexRows();
      },
    });
  }

  $("#cfc-cat-search").on("input", function () {
    const q = $(this).val().toLowerCase().trim();

    if (!q) {
      $(".cfc-cat-item, .cfc-cat-link").show();
      return;
    }

    $(".cfc-cat-item").each(function () {
      const name = $(this)
        .find("> .cfc-cat-link .cfc-cat-name")
        .text()
        .toLowerCase();
      const match = name.includes(q);
      $(this).toggle(match);

      if (match) {
        $(this).parents(".cfc-cat-item").show();
      }
    });
  });

  $(document).on("click", ".cfc-cat-toggle", function (e) {
    e.preventDefault();
    const $item = $(this).closest(".cfc-cat-item");
    $item.toggleClass("is-open");
  });

  $("#cfc-add-filter").on("click", () => {
    const list = document.getElementById("cfc-filters-list");
    const tmpl = document.getElementById("cfc-row-template");
    const idx = list.querySelectorAll(".cfc-filter-row").length;
    const html = tmpl.innerHTML.replace(/__INDEX__/g, idx);

    list.insertAdjacentHTML("beforeend", html);
    reindexRows();
    bindRowEvents(list.lastElementChild);
    $("#cfc-empty-state").hide();
    list.lastElementChild.querySelector(".cfc-field-name")?.focus();
  });

  function bindRowEvents(row) {
    const $row = $(row);

    $row.find(".cfc-field-type").on("change", function () {
      const isRange = this.value === "range";
      $row.find(".cfc-range-fields").css("visibility", isRange ? "" : "hidden");

      if (isRange && !$row.find(".cfc-field-name").val()) {
        const label = $row
          .find(".cfc-field-attr option:selected")
          .text()
          .replace(/\s*\(pa_.*?\)/, "")
          .trim();
        if (label && label !== "— select attribute —") {
          $row.find(".cfc-field-name").val(label);
        }
      }
    });

    $row.find(".cfc-field-attr").on("change", function () {
      const $name = $row.find(".cfc-field-name");
      if (!$name.val()) {
        const label = $(this)
          .find("option:selected")
          .text()
          .replace(/\s*\(pa_.*?\)/, "")
          .trim();
        if (
          label &&
          label !== "— select attribute —" &&
          label !== "Price (built-in)"
        ) {
          $name.val(label);
        }
      }

      if ($(this).val() === "price") {
        $row.find(".cfc-field-type").val("range").trigger("change");
      }
    });

    $row.find(".cfc-field-enabled").on("change", function () {
      $row.toggleClass("is-disabled", !this.checked);
    });

    $row.find(".cfc-remove-row").on("click", () => {
      if ($(".cfc-filter-row").length <= 1) {
        if (!confirm("Remove the last filter?")) {
          return;
        }
      }
      $row.slideUp(150, () => {
        $row.remove();
        reindexRows();
        if (!$(".cfc-filter-row").length) {
          $("#cfc-empty-state").show();
        }
      });
    });
  }

  $(".cfc-filter-row").each(function () {
    bindRowEvents(this);
  });

  function reindexRows() {
    document.querySelectorAll(".cfc-filter-row").forEach((row, i) => {
      row.dataset.index = i;
      row.querySelectorAll("[name]").forEach((el) => {
        el.name = el.name.replace(/filters\[\d+\]/, `filters[${i}]`);
      });
    });
  }

  function collectFilters() {
    const filters = [];
    document.querySelectorAll(".cfc-filter-row").forEach((row) => {
      const get = (sel) => row.querySelector(sel);
      filters.push({
        display_name: get(".cfc-field-name")?.value || "",
        attr_slug: get(".cfc-field-attr")?.value || "",
        type: get(".cfc-field-type")?.value || "checkbox",
        range_min: parseFloat(get(".cfc-field-min")?.value || 0),
        range_max: parseFloat(get(".cfc-field-max")?.value || 9999),
        min_products: parseInt(get(".cfc-field-min-products")?.value || 0),
        order: parseInt(get(".cfc-field-order")?.value || 10),
        enabled: get(".cfc-field-enabled")?.checked ? 1 : 0,
      });
    });
    return filters;
  }

  $("#cfc-save").on("click", function () {
    const categoryId = $(this).data("category");
    const $btn = $(this);
    $btn.prop("disabled", true).text("Saving...");

    $.post(ajax_url, {
      action: "cfc_save_filters",
      nonce,
      category_id: categoryId,
      filters: collectFilters(),
    })
      .done((res) => {
        if (res.success) {
          showNotice(`Saved ${res.data.count} filter(s)`, "success");
          updateHistoryBtn(res.data.history);
          markCategoryConfigured(categoryId, true);
        } else {
          showNotice(`Error: ${res.data}`, "error");
        }
      })
      .fail(() => showNotice("Network error", "error"))
      .always(() => $btn.prop("disabled", false).text("Save"));
  });

  $("#cfc-reset-btn").on("click", function (e) {
    e.preventDefault();
    if (!confirm("Delete custom settings and revert to inheritance?")) {
      return;
    }

    $.post(ajax_url, {
      action: "cfc_reset_category",
      nonce,
      category_id: $(this).data("category"),
    }).done(() => location.reload());
  });

  $(".cfc-btn-bulk-enable").on("click", () => {
    $(".cfc-field-enabled").prop("checked", true);
    $(".cfc-filter-row").removeClass("is-disabled");
    showNotice("All filters enabled", "info");
  });

  $(".cfc-btn-bulk-disable").on("click", () => {
    $(".cfc-field-enabled").prop("checked", false);
    $(".cfc-filter-row").addClass("is-disabled");
    showNotice("All filters disabled", "info");
  });

  $("#cfc-copy-btn").on("click", () => openModal("#cfc-copy-modal"));

  $("#cfc-copy-search").on("input", function () {
    const q = $(this).val().toLowerCase();
    $(".cfc-copy-cat-item").each(function () {
      $(this).toggle($(this).text().toLowerCase().includes(q));
    });
  });

  $("#cfc-copy-select-all").on("click", () =>
    $(".cfc-copy-cat-check:visible").prop("checked", true),
  );
  $("#cfc-copy-select-none").on("click", () =>
    $(".cfc-copy-cat-check").prop("checked", false),
  );

  $("#cfc-copy-confirm").on("click", function () {
    const fromId = $(this).data("from");
    const toIds = $(".cfc-copy-cat-check:checked")
      .map((_, el) => el.value)
      .get();
    const overwr = $("#cfc-copy-overwrite").is(":checked");

    if (!toIds.length) {
      alert("Select at least one category");
      return;
    }

    $(this).prop("disabled", true).text("Copying...");

    $.post(ajax_url, {
      action: "cfc_copy_filters",
      nonce,
      from_id: fromId,
      to_ids: toIds,
      overwrite: overwr ? 1 : 0,
    })
      .done((res) => {
        if (res.success) {
          const { copied, skipped } = res.data;
          closeModal("#cfc-copy-modal");
          showNotice(
            `Copied: ${copied} categories. Skipped: ${skipped}`,
            "success",
          );
          toIds.forEach((id) => markCategoryConfigured(id, true));
        } else {
          showNotice(`Error: ${res.data}`, "error");
        }
      })
      .always(() =>
        $("#cfc-copy-confirm").prop("disabled", false).text("Copy"),
      );
  });

  $("#cfc-history-btn").on("click", () => {
    const categoryId = $("#cfc-save").data("category");
    openModal("#cfc-history-modal");

    $.post(ajax_url, {
      action: "cfc_get_history",
      nonce,
      category_id: categoryId,
    }).done((res) => {
      if (!res.success || !res.data.length) {
        $("#cfc-history-list").html("<p>History is empty</p>");
        return;
      }

      const rows = res.data
        .map(
          (h) => `
                <div class="cfc-history-row">
                    <div class="cfc-history-meta">
                        <strong>${h.user_name}</strong>
                        <span>${h.changed_at}</span>
                        ${h.note ? `<em>${h.note}</em>` : ""}
                    </div>
                    <div class="cfc-history-info">
                        Filters: ${h.filter_count}
                    </div>
                    <button class="button cfc-restore-btn"
                            data-history="${h.id}"
                            data-category="${categoryId}">
                        Restore
                    </button>
                </div>`,
        )
        .join("");

      $("#cfc-history-list").html(rows);
    });
  });

  $(document).on("click", ".cfc-restore-btn", function () {
    if (
      !confirm(
        "Restore this version? Current settings will be saved to history.",
      )
    ) {
      return;
    }

    const $btn = $(this);
    const histId = $btn.data("history");
    const catId = $btn.data("category");

    $btn.prop("disabled", true).text("Restoring...");

    $.post(ajax_url, {
      action: "cfc_restore_history",
      nonce,
      history_id: histId,
      category_id: catId,
    })
      .done((res) => {
        if (res.success) {
          closeModal("#cfc-history-modal");
          showNotice(`Restored ${res.data.count} filter(s)`, "success");
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotice(`Error: ${res.data}`, "error");
        }
      })
      .always(() => $btn.prop("disabled", false).text("Restore"));
  });

  $("#cfc-preview-btn").on("click", function () {
    const categoryId = $(this).data("category");
    openModal("#cfc-preview-modal");
    $("#cfc-preview-content").html(`
            <div class="cfc-preview-loading">
                <span class="spinner is-active"></span>
                Loading live data from WooCommerce...
            </div>`);

    $.post(ajax_url, {
      action: "cfc_preview_filters",
      nonce,
      category_id: categoryId,
    })
      .done((res) => {
        if (!res.success) {
          $("#cfc-preview-content").html(`<p class="error">${res.data}</p>`);
          return;
        }

        const { filters, product_count, category_name } = res.data;
        $("#cfc-preview-title").text(`Preview — ${category_name}`);
        $("#cfc-preview-info").text(`Products in category: ${product_count}`);

        if (!filters.length) {
          $("#cfc-preview-content").html("<p>No active filters</p>");
          return;
        }

        const html = filters
          .map((f) => renderPreviewFilter(f, product_count))
          .join("");
        $("#cfc-preview-content").html(
          `<div class="cfc-preview-panel">${html}</div>`,
        );
      })
      .fail(() =>
        $("#cfc-preview-content").html('<p class="error">Loading error</p>'),
      );
  });

  function renderPreviewFilter(f, totalProducts) {
    const conditionWarning =
      f.min_products_condition && totalProducts < f.min_products_condition
        ? `<div class="cfc-preview-hidden-notice">
                   ⚠ Filter hidden: products ${totalProducts} < ${f.min_products_condition}
               </div>`
        : "";

    if (f.type === "range") {
      const pct = (val) => ((val - f.min) / (f.max - f.min)) * 100;
      return `
                <div class="cfc-preview-item">
                    ${conditionWarning}
                    <div class="cfc-preview-label">${f.name}</div>
                    <div class="cfc-preview-range-widget">
                        <div class="cfc-preview-track">
                            <div class="cfc-preview-fill"
                                 style="left:10%;right:20%"></div>
                        </div>
                        <div class="cfc-preview-vals">
                            <span>${Math.round(f.min)}</span>
                            <span>${Math.round(f.max)}</span>
                        </div>
                        ${
                          f.count != null
                            ? `<div class="cfc-preview-count">${f.count} products</div>`
                            : ""
                        }
                    </div>
                </div>`;
    }

    const opts = f.options || [];
    const counts = f.product_counts || [];
    const showAll = opts.length <= 6;
    const visibleOpts = showAll ? opts : opts.slice(0, 5);

    const checkboxes = visibleOpts
      .map(
        (o, i) => `
            <label class="cfc-preview-check">
                <input type="checkbox" disabled />
                <span>${o}</span>
                ${
                  counts[i] != null
                    ? `<span class="cfc-preview-term-count">${counts[i]}</span>`
                    : ""
                }
            </label>`,
      )
      .join("");

    const moreLink = !showAll
      ? `<a href="#" class="cfc-preview-more">
                   ${opts.length - 5} more...
               </a>`
      : "";

    const emptyNote = !opts.length
      ? '<p class="cfc-preview-note">Values are loaded from WooCommerce attributes</p>'
      : "";

    return `
            <div class="cfc-preview-item">
                ${conditionWarning}
                <div class="cfc-preview-label">
                    ${f.name}
                    ${f.total_terms ? `<span class="cfc-preview-total">${f.total_terms} values</span>` : ""}
                </div>
                <div class="cfc-preview-checks">
                    ${checkboxes}
                    ${moreLink}
                    ${emptyNote}
                </div>
            </div>`;
  }

  let importFile = null;
  let importParsed = null; // { _format, rows } or null for CSV

  function setImportFile(file) {
    importFile = file;
    importParsed = null;
    if (file) {
      $("#cfc-import-file-info").text(
        `${file.name} (${(file.size / 1024).toFixed(1)} KB)`,
      );
      $("#cfc-file-selected").show();
      $("#cfc-dropzone").addClass("has-file");
      $("#cfc-preview-import-btn").prop("disabled", false);
    } else {
      $("#cfc-file-selected").hide();
      $("#cfc-dropzone").removeClass("has-file");
      $("#cfc-preview-import-btn").prop("disabled", true);
    }
  }

  const dropzone = document.getElementById("cfc-dropzone");
  if (dropzone) {
    dropzone.addEventListener("dragover", (e) => {
      e.preventDefault();
      dropzone.classList.add("is-over");
    });
    dropzone.addEventListener("dragleave", () => {
      dropzone.classList.remove("is-over");
    });
    dropzone.addEventListener("drop", (e) => {
      e.preventDefault();
      dropzone.classList.remove("is-over");
      const file = e.dataTransfer.files[0];
      if (file) {
        setImportFile(file);
      }
    });
  }

  $("#cfc-xlsx-file").on("change", function () {
    setImportFile(this.files[0] || null);
  });

  $("#cfc-file-clear").on("click", () => {
    $("#cfc-xlsx-file").val("");
    setImportFile(null);
  });

  const IMPORT_KEY_MAP = {
    category: "category",
    filter: "filter_name",
    "filter name": "filter_name",
    type: "type",
    "wc attribute": "attr_slug",
    "woocommerce attribute": "attr_slug",
    attribute: "attr_slug",
    min: "range_min",
    minimum: "range_min",
    max: "range_max",
    maximum: "range_max",
    order: "order",
    enabled: "enabled",
    "min products": "min_products",
    "min. products": "min_products",
  };

  $("#cfc-preview-import-btn").on("click", async function () {
    if (!importFile) {
      return;
    }

    const $btn = $(this);
    $btn.prop("disabled", true).text("Reading file...");

    try {
      const isCsv = importFile.name.toLowerCase().endsWith(".csv");

      if (isCsv) {
        const text = await readFileAsText(importFile);
        const delimiter =
          (text.match(/;/g) || []).length >= (text.match(/,/g) || []).length
            ? ";"
            : ",";
        const lines = text.split(/\r?\n/).filter(Boolean);
        const headers = lines[0]
          .split(delimiter)
          .map((h) => h.trim().replace(/^"|"$/g, ""));

        const normHeaders = headers.map(
          (h) => IMPORT_KEY_MAP[h.toLowerCase()] || h.toLowerCase(),
        );

        const rows = lines
          .slice(1)
          .map((line) => {
            const cells = line
              .split(delimiter)
              .map((c) => c.trim().replace(/^"|"$/g, ""));
            const row = {};
            normHeaders.forEach((k, i) => {
              row[k] = cells[i] || "";
            });
            return row;
          })
          .filter((r) => r.category && r.filter_name);

        importParsed = null; // CSV is imported via a dedicated endpoint (file upload)
        await requestPreview({ _format: "flat", rows });
      } else {
        if (typeof XLSX === "undefined") {
          throw new Error("SheetJS library not loaded. Refresh the page.");
        }
        const buffer = await readFileAsArrayBuffer(importFile);
        const workbook = XLSX.read(new Uint8Array(buffer), { type: "array" });
        const parsed = parseWorkbook(workbook);
        if (!parsed) {
          throw new Error("Could not recognize the file format.");
        }
        importParsed = parsed;
        await requestPreview(parsed);
      }
    } catch (err) {
      showNotice(`File read error: ${err.message}`, "error");
    } finally {
      $btn.prop("disabled", false).text("Check file →");
    }
  });

  async function requestPreview(parsed) {
    const rows = parsed._format === "flat" ? parsed.rows : [];

    const res = await $.post(ajax_url, {
      action: "cfc_import_preview",
      nonce,
      data: JSON.stringify({ _format: "flat", rows }),
    });

    if (!res.success) {
      showNotice(`Preview error: ${res.data || "unknown error"}`, "error");
      return;
    }

    renderPreviewStep(res.data);
    $("#cfc-import-step-upload").hide();
    $("#cfc-import-step-preview").show();
    $("html, body").animate(
      { scrollTop: $("#cfc-import-step-preview").offset().top - 40 },
      300,
    );
  }

  function renderPreviewStep(data) {
    const { columns, rows, warnings, total } = data;

    let tableHtml = `<div class="cfc-preview-table-wrap"><table class="cfc-preview-table widefat striped">
      <thead><tr>${columns.map((c) => `<th>${c}</th>`).join("")}</tr></thead><tbody>`;
    rows.forEach((row) => {
      tableHtml += `<tr>${row.map((cell) => `<td>${cell || "—"}</td>`).join("")}</tr>`;
    });
    tableHtml += `</tbody></table></div>`;
    if (rows.length < total) {
      tableHtml += `<p class="description">Showing the first ${rows.length} of ${total} rows.</p>`;
    }
    $("#cfc-preview-table-wrap").html(tableHtml);

    if (warnings && warnings.length) {
      const warnHtml = `<div class="cfc-warnings">${warnings
        .map((w) => `<div class="cfc-warnings__item">⚠ ${w}</div>`)
        .join("")}</div>`;
      $("#cfc-preview-warnings").html(warnHtml);
    } else {
      $("#cfc-preview-warnings").html(
        `<div class="cfc-warnings__ok">✅ No problems found</div>`,
      );
    }

    $("#cfc-preview-total").text(`Total rows to import: ${total}`);
  }

  $("#cfc-preview-back-btn").on("click", () => {
    $("#cfc-import-step-preview").hide();
    $("#cfc-import-step-upload").show();
  });

  $("#cfc-import-btn").on("click", function () {
    const $btn = $(this);
    $btn.prop("disabled", true).text("Importing...");
    $("#cfc-import-progress").show();

    const mode = $("#cfc-import-mode").val();
    const isCsv = importFile && importFile.name.toLowerCase().endsWith(".csv");

    if (isCsv) {
      const dryRun = $("#cfc-dry-run").is(":checked") ? 1 : 0;
      const formData = new FormData();
      formData.append("action", "cfc_import_csv");
      formData.append("nonce", nonce);
      formData.append("mode", mode);
      formData.append("dry_run", dryRun);
      formData.append("file", importFile);

      $.ajax({
        url: ajax_url,
        type: "POST",
        data: formData,
        processData: false,
        contentType: false,
      })
        .done((res) => {
          if (res.success) {
            finishImport(res.data);
          } else {
            showNotice(`Import error: ${res.data || "unknown error"}`, "error");
          }
        })
        .fail(() => showNotice("Network error during import", "error"))
        .always(() => {
          $btn.prop("disabled", false).text("Import all rows");
          $("#cfc-import-progress").hide();
        });
    } else {
      if (!importParsed) {
        showNotice("File not recognized. Select the file again.", "error");
        $btn.prop("disabled", false).text("Import all rows");
        return;
      }

      const totalRows =
        importParsed._format === "flat"
          ? importParsed.rows.length
          : Object.values(importParsed).reduce(
              (s, arr) => s + (Array.isArray(arr) ? arr.length : 0),
              0,
            );
      $("#cfc-import-log").text(`Processing ${totalRows} rows...`);

      const dryRun = $("#cfc-dry-run").is(":checked") ? 1 : 0;

      $.post(ajax_url, {
        action: "cfc_import_xlsx",
        nonce,
        mode,
        dry_run: dryRun,
        data: JSON.stringify(importParsed),
      })
        .done((res) => {
          if (res.success) {
            finishImport(res.data);
          } else {
            showNotice(`Import error: ${res.data || "unknown error"}`, "error");
          }
        })
        .fail(() => showNotice("Network error during import", "error"))
        .always(() => {
          $btn.prop("disabled", false).text("Import all rows");
          $("#cfc-import-progress").hide();
        });
    }
  });

  function finishImport(data) {
    renderImportResults(data);
    const msg = data.dry_run
      ? `Dry run: ${data.imported} rows passed validation, no changes made`
      : `Import complete: ${data.imported} categories updated`;
    showNotice(msg, "success");
    $("#cfc-import-step-preview").hide();
    $("#cfc-import-results").show();
    $("html, body").animate(
      { scrollTop: $("#cfc-import-results").offset().top - 40 },
      300,
    );
  }

  $("#cfc-import-reset-btn").on("click", () => {
    importFile = null;
    importParsed = null;
    $("#cfc-xlsx-file").val("");
    setImportFile(null);
    $("#cfc-import-results").hide();
    $("#cfc-import-step-preview").hide();
    $("#cfc-import-step-upload").show();
    $("#cfc-preview-table-wrap, #cfc-preview-warnings").html("");
    $("#cfc-preview-total").text("");
  });

  function renderImportResults(data) {
    const { imported, skipped, errors, categories, dry_run } = data;

    const importLabel = dry_run ? "🔍 Will pass validation:" : "✅ Imported:";
    const statusSuffix = dry_run ? " (dry run)" : "";

    let html = `
      <tr><td>${importLabel}</td><td><strong>${imported}</strong>${statusSuffix}</td></tr>
      <tr><td>⏭ Skipped:</td><td>${skipped}</td></tr>`;

    if (categories && categories.length) {
      html += '<tr><td colspan="2"><strong>Details:</strong></td></tr>';
      categories.forEach((c) => {
        const icon =
          c.status === "imported"
            ? "✅"
            : c.status === "would_import"
              ? "🔍"
              : "⏭";
        const info = c.count ? ` (${c.count} filters)` : "";
        html += `<tr><td>${icon} ${c.name}</td><td>${c.status}${info}</td></tr>`;
      });
    }

    if (errors && errors.length) {
      errors.forEach((e) => {
        html += `<tr><td colspan="2" style="color:#d63638">❌ ${e}</td></tr>`;
      });
    }

    $("#cfc-import-results-body").html(html);
  }

  function readFileAsArrayBuffer(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => resolve(e.target.result);
      reader.onerror = reject;
      reader.readAsArrayBuffer(file);
    });
  }

  function readFileAsText(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => resolve(e.target.result);
      reader.onerror = reject;
      reader.readAsText(file, "UTF-8");
    });
  }

  function parseWorkbook(workbook) {
    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
    return parseFlatFormat(firstSheet);
  }

  function parseFlatFormat(sheet) {
    const jsonRows = XLSX.utils.sheet_to_json(sheet, { defval: "" });

    const rows = jsonRows
      .map((row) => {
        const normalized = {};
        for (const [key, val] of Object.entries(row)) {
          const normKey =
            IMPORT_KEY_MAP[key.toLowerCase().trim()] ||
            key.toLowerCase().trim();
          normalized[normKey] = String(val).trim();
        }
        return normalized;
      })
      .filter((r) => r.category && r.filter_name);

    return { _format: "flat", rows };
  }

  $("#cfc-download-template").on("click", function () {
    if (typeof XLSX === "undefined") {
      showNotice(
        "SheetJS library not loaded. Refresh the page (Ctrl+Shift+R).",
        "error",
      );
      return;
    }

    const $btn = $(this);
    $btn.prop("disabled", true).text("Generating...");

    $.post(ajax_url, { action: "cfc_download_template", nonce })
      .done((res) => {
        if (!res.success) {
          showNotice(
            `Template generation error: ${res.data || "unknown error"}`,
            "error",
          );
          return;
        }

        try {
          const { categories, attributes, filter_types } = res.data;
          generateTemplate(categories, attributes, filter_types);
        } catch (err) {
          showNotice(`File creation error: ${err.message}`, "error");
        }
      })
      .fail((xhr, status, err) => {
        showNotice(
          `Network error while downloading template: ${err || status}`,
          "error",
        );
      })
      .always(() =>
        $btn.prop("disabled", false).text("📥 Download Excel template"),
      );
  });

  function generateTemplate(categories, attributes, filterTypes) {
    const wb = XLSX.utils.book_new();

    const header = [
      "Category",
      "Filter",
      "Type",
      "WC Attribute",
      "Min",
      "Max",
      "Order",
      "Enabled",
      "Min. products",
    ];
    const examples = [
      ["--- EXAMPLES (delete these rows) ---", "", "", "", "", "", "", "", ""],
      [
        "Spinning reels",
        "Price",
        "range",
        "price",
        "500",
        "15000",
        "1",
        "Yes",
        "0",
      ],
      [
        "Spinning reels",
        "Brand",
        "checkbox",
        "pa_brand",
        "",
        "",
        "2",
        "Yes",
        "0",
      ],
      [
        "Spinning reels",
        "Series",
        "checkbox",
        "pa_series",
        "",
        "",
        "3",
        "Yes",
        "3",
      ],
      [
        "Spinning rods",
        "Price",
        "range",
        "price",
        "1000",
        "50000",
        "1",
        "Yes",
        "0",
      ],
      [
        "Spinning rods",
        "Brand",
        "checkbox",
        "pa_brand",
        "",
        "",
        "2",
        "Yes",
        "0",
      ],
      [
        "Spinning rods",
        "Action",
        "radio",
        "pa_action",
        "",
        "",
        "3",
        "Yes",
        "0",
      ],
      ["--- START YOUR DATA BELOW ---", "", "", "", "", "", "", "", ""],
    ];
    const ws1 = XLSX.utils.aoa_to_sheet([header, ...examples]);
    ws1["!cols"] = [
      { wch: 30 },
      { wch: 25 },
      { wch: 12 },
      { wch: 25 },
      { wch: 8 },
      { wch: 8 },
      { wch: 8 },
      { wch: 10 },
      { wch: 12 },
    ];
    XLSX.utils.book_append_sheet(wb, ws1, "Filters");

    const catHeader = ["Category name", "Slug", "Products"];
    const catRows = categories.map((c) => [c.name, c.slug, c.count]);
    const ws2 = XLSX.utils.aoa_to_sheet([catHeader, ...catRows]);
    ws2["!cols"] = [{ wch: 35 }, { wch: 30 }, { wch: 10 }];
    XLSX.utils.book_append_sheet(wb, ws2, "WooCommerce Categories");

    const attrHeader = ["Attribute slug", "Name"];
    const attrRows = [
      ["price", "Price (built-in)"],
      ...attributes.map((a) => [a.slug, a.label]),
    ];
    const ws3 = XLSX.utils.aoa_to_sheet([attrHeader, ...attrRows]);
    ws3["!cols"] = [{ wch: 30 }, { wch: 30 }];
    XLSX.utils.book_append_sheet(wb, ws3, "WooCommerce Attributes");

    const typesHeader = ["Type", "Description", "Example"];
    const typesRows = [
      [
        "checkbox",
        "Multiple choice (checkboxes)",
        "Brand: Shimano, Daiwa, Rapala",
      ],
      [
        "radio",
        "Single choice (radio buttons)",
        "Action: Fast / Medium / Slow",
      ],
      ["range", "Slider (min—max)", "Price: 500 — 15000"],
      ["toggle", "On/off switch", "In stock: on/off"],
      ["color", "Color swatches", "Color: Red, Blue, Green"],
    ];
    const ws4 = XLSX.utils.aoa_to_sheet([typesHeader, ...typesRows]);
    ws4["!cols"] = [{ wch: 12 }, { wch: 35 }, { wch: 40 }];
    XLSX.utils.book_append_sheet(wb, ws4, "Filter types");

    XLSX.writeFile(wb, "filter-config-template.xlsx");
    showNotice("Template downloaded", "success");
  }

  $("#cfc-export-btn").on("click", function () {
    if (typeof XLSX === "undefined") {
      showNotice(
        "SheetJS library not loaded. Refresh the page (Ctrl+Shift+R).",
        "error",
      );
      return;
    }

    const $btn = $(this);
    $btn.prop("disabled", true).text("Exporting...");

    $.post(ajax_url, { action: "cfc_export_filters", nonce })
      .done((res) => {
        if (!res.success) {
          showNotice(`Export error: ${res.data || "unknown error"}`, "error");
          return;
        }

        if (!res.data.rows || !res.data.rows.length) {
          showNotice("No configured filters to export", "info");
          return;
        }

        try {
          const wb = XLSX.utils.book_new();
          const header = [
            "Category",
            "Filter",
            "Type",
            "WC Attribute",
            "Min",
            "Max",
            "Order",
            "Enabled",
            "Min. products",
          ];
          const rows = res.data.rows.map((r) => [
            r.category,
            r.filter_name,
            r.type,
            r.attr_slug,
            r.range_min,
            r.range_max,
            r.order,
            r.enabled,
            r.min_products,
          ]);
          const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);
          ws["!cols"] = [
            { wch: 30 },
            { wch: 25 },
            { wch: 12 },
            { wch: 25 },
            { wch: 8 },
            { wch: 8 },
            { wch: 8 },
            { wch: 10 },
            { wch: 12 },
          ];
          XLSX.utils.book_append_sheet(wb, ws, "Filters");

          const date = new Date().toISOString().slice(0, 10);
          XLSX.writeFile(wb, `filter-config-export-${date}.xlsx`);
          showNotice(`Exported ${res.data.rows.length} rows`, "success");
        } catch (err) {
          showNotice(`File creation error: ${err.message}`, "error");
        }
      })
      .fail((xhr, status, err) => {
        showNotice(`Network error during export: ${err || status}`, "error");
      })
      .always(() =>
        $btn.prop("disabled", false).text("📤 Export current filters"),
      );
  });

  $("#cfc-export-csv-btn").on("click", function () {
    const $btn = $(this);
    $btn.prop("disabled", true).text("Exporting...");

    $.post(ajax_url, { action: "cfc_export_filters", nonce })
      .done((res) => {
        if (!res.success) {
          showNotice(`Export error: ${res.data || "unknown error"}`, "error");
          return;
        }
        if (!res.data.rows || !res.data.rows.length) {
          showNotice("No configured filters to export", "info");
          return;
        }
        const header = [
          "Category",
          "Filter",
          "Type",
          "WC Attribute",
          "Min",
          "Max",
          "Order",
          "Enabled",
          "Min. products",
        ];
        const rows = res.data.rows.map((r) => [
          r.category,
          r.filter_name,
          r.type,
          r.attr_slug,
          r.range_min,
          r.range_max,
          r.order,
          r.enabled,
          r.min_products,
        ]);
        const csvContent = [header, ...rows]
          .map((row) =>
            row
              .map((cell) => `"${String(cell ?? "").replace(/"/g, '""')}"`)
              .join(";"),
          )
          .join("\n");
        const blob = new Blob([`﻿${csvContent}`], {
          type: "text/csv;charset=utf-8;",
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        const date = new Date().toISOString().slice(0, 10);
        a.href = url;
        a.download = `filter-config-export-${date}.csv`;
        a.click();
        URL.revokeObjectURL(url);
        showNotice(`Exported ${res.data.rows.length} rows to CSV`, "success");
      })
      .fail(() => showNotice("Network error during export", "error"))
      .always(() => $btn.prop("disabled", false).text("📄 Export to CSV"));
  });

  $("#cfc-run-diagnostics").on("click", function () {
    const $btn = $(this);
    $btn.prop("disabled", true).text("Checking...");
    $("#cfc-diagnostics-result").hide();
    $("#cfc-ok-notice").hide();

    $.post(ajax_url, { action: "cfc_run_diagnostics", nonce })
      .done((res) => {
        if (!res.success) {
          showNotice(
            `Diagnostics error: ${res.data || "unknown error"}`,
            "error",
          );
          return;
        }
        const d = res.data;
        const stats = d.stats || {};

        $("#stat-configured").text(stats.configured ?? 0);
        $("#stat-inherited").text(stats.inherited ?? 0);
        $("#stat-total-categories").text(stats.total_categories ?? 0);
        $("#stat-total-filters").text(stats.total_filters ?? 0);

        const hasBroken = d.broken && d.broken.length;
        const hasOrphaned = d.orphaned && d.orphaned.length;

        const $brokenList = $("#cfc-broken-list");
        const $brokenTitle = $("#cfc-broken-title");
        $brokenList.empty();
        if (hasBroken) {
          $brokenTitle.text(`❌ Broken filters found: ${d.broken.length}`);
          const ul = $('<ul class="cfc-diag-list">');
          d.broken.forEach((item) => {
            ul.append(
              `<li><strong>${item.category}</strong>: attribute <code>${item.attr_slug}</code> — ${item.filter_name}</li>`,
            );
          });
          $brokenList.append(ul);
        } else {
          $brokenTitle.text("✅ No broken filters found");
        }

        const $orphanedList = $("#cfc-orphaned-list");
        const $orphanedTitle = $("#cfc-orphaned-title");
        $orphanedList.empty();
        if (hasOrphaned) {
          $orphanedTitle.text(
            `⚠️ Orphaned configs found: ${d.orphaned.length}`,
          );
          const ul = $('<ul class="cfc-diag-list">');
          d.orphaned.forEach((item) => {
            ul.append(
              `<li>Category ID <strong>${item.category_id}</strong> — category deleted from WordPress</li>`,
            );
          });
          $orphanedList.append(ul);
          $("#cfc-fix-orphaned-wrap").show();
        } else {
          $orphanedTitle.text("✅ No orphaned configs");
          $("#cfc-fix-orphaned-wrap").hide();
        }

        if (!hasBroken && !hasOrphaned) {
          $("#cfc-ok-notice").show();
        }

        $("#cfc-diagnostics-result").show();
      })
      .fail(() => showNotice("Network error during diagnostics", "error"))
      .always(() => $btn.prop("disabled", false).text("🔍 Run check"));
  });

  $("#cfc-fix-orphaned").on("click", function () {
    if (
      !confirm(
        "Delete all configs of removed categories? This action is irreversible.",
      )
    ) {
      return;
    }
    const $btn = $(this);
    $btn.prop("disabled", true).text("Cleaning up...");

    $.post(ajax_url, { action: "cfc_fix_orphaned", nonce })
      .done((res) => {
        if (res.success) {
          showNotice(`Deleted ${res.data.deleted} orphaned records`, "success");
          const $status = $("#cfc-fix-status");
          $status
            .text(`✓ Deleted ${res.data.deleted} records`)
            .css("color", "green");
          $("#cfc-orphaned-list").html(
            '<p style="color:#1a4731">✅ Cleaned up successfully</p>',
          );
          $btn.hide();
          $("#stat-configured").text(
            Math.max(
              0,
              parseInt($("#stat-configured").text(), 10) - res.data.deleted,
            ),
          );
        } else {
          showNotice(`Cleanup error: ${res.data || ""}`, "error");
        }
      })
      .fail(() => showNotice("Network error", "error"))
      .always(() =>
        $btn
          .prop("disabled", false)
          .text("🗑 Delete configs for non-existent categories"),
      );
  });

  $("#cfc-save-advanced").on("click", function () {
    const $btn = $(this);
    $btn.prop("disabled", true).text("Saving...");

    $.post(ajax_url, {
      action: "cfc_save_advanced_settings",
      nonce,
      delete_on_uninstall: $("#cfc-delete-on-uninstall").is(":checked") ? 1 : 0,
    })
      .done((res) => {
        const el = $("#cfc-advanced-status");
        el.text(res.success ? "✓ Saved" : "✗ Error").css(
          "color",
          res.success ? "green" : "red",
        );
        setTimeout(() => el.text(""), 3000);
      })
      .fail(() => showNotice("Error saving settings", "error"))
      .always(() => $btn.prop("disabled", false).text("Save"));
  });

  $("#cfc-save-webhook").on("click", () => {
    $.post(ajax_url, {
      action: "cfc_save_webhook_settings",
      nonce,
      url: $("#cfc-webhook-url").val(),
      secret: $("#cfc-webhook-secret").val(),
      log: $("#cfc-webhook-log").is(":checked") ? 1 : 0,
    }).done((res) => {
      const el = $("#cfc-webhook-status");
      el.text(res.success ? "✓ Saved" : "✗ Error").css(
        "color",
        res.success ? "green" : "red",
      );
      setTimeout(() => el.text(""), 3000);
    });
  });

  $("#cfc-test-webhook").on("click", function () {
    const $btn = $(this);
    $btn.prop("disabled", true).text("Checking...");

    $.post(ajax_url, {
      action: "cfc_test_webhook",
      nonce,
      url: $("#cfc-webhook-url").val(),
      secret: $("#cfc-webhook-secret").val(),
    })
      .done((res) => {
        const el = $("#cfc-webhook-status");
        el.text(res.success ? `✓ ${res.data}` : `✗ ${res.data}`).css(
          "color",
          res.success ? "green" : "red",
        );
      })
      .always(() => $btn.prop("disabled", false).text("Test webhook"));
  });

  function openModal(selector) {
    $(selector).show();
    $("body").addClass("cfc-modal-open");
  }

  function closeModal(selector) {
    $(selector).hide();
    $("body").removeClass("cfc-modal-open");
  }

  $(document).on("click", ".cfc-modal-close, .cfc-modal-backdrop", function () {
    closeModal($(this).closest(".cfc-modal"));
  });

  $(document).on("keydown", (e) => {
    if (e.key === "Escape") {
      closeModal(".cfc-modal:visible");
    }
  });

  function showNotice(msg, type) {
    const types = {
      success: "notice-success",
      error: "notice-error",
      info: "notice-info",
    };
    const $n =
      $(`<div class="notice ${types[type] || "notice-info"} is-dismissible cfc-inline-notice">
            <p>${msg}</p></div>`);
    $(".cfc-wrap h1").after($n);
    setTimeout(() => $n.fadeOut(400, () => $n.remove()), 4000);
  }

  function updateHistoryBtn(history) {
    const $btn = $("#cfc-history-btn");
    if (history && history.length) {
      if ($btn.length) {
        $btn.text(`History (${history.length})`).show();
      }
    }
  }

  function markCategoryConfigured(categoryId, configured) {
    const $link = $(`.cfc-cat-link[data-id="${categoryId}"]`);
    if (configured) {
      if (!$link.find(".cfc-configured-dot").length) {
        $link.append(
          '<span class="cfc-configured-dot" title="Custom filters configured">●</span>',
        );
      }
    } else {
      $link.find(".cfc-configured-dot").remove();
    }
  }
});
