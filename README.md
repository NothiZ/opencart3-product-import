<h1 align="center">OpenCart 3 Product Import</h1>

<p align="center">Import products from Excel and an image ZIP directly in the OpenCart admin.</p>

<p align="center">
  <a href="https://github.com/NothiZ/opencart3-product-import/releases/latest">Download latest release</a> ·
  <a href="./README.zh-CN.md">简体中文</a> ·
  <a href="https://github.com/NothiZ/opencart3-product-import/actions">Test runs</a> ·
  <a href="./LICENSE">MIT license</a>
</p>

![OpenCart upload screen with the Excel workbook, image ZIP and outer-folder option](docs/screenshots/upload.png)

## Quick start

1. Download `nothiz_product_import_oc3.ocmod.zip` from the latest release assets. [GitHub Releases](https://github.com/NothiZ/opencart3-product-import/releases/latest)
2. In the OpenCart admin, upload the ZIP under **Extensions → Installer**.
3. Open **Extensions → Extensions**, select **Modules**, and install **Product Import**. Open **Product Import** from the sidebar.

When upgrading, uninstall the previous module and remove its installer entry before uploading the new ZIP. Then install the module again.

## Import workflow

| Step | What to do |
| --- | --- |
| **Upload files** | Choose the Excel workbook and, for new products, an image ZIP. |
| **Check files** | Review categories and row statuses before importing. Existing SKU or Model matches are skipped. Duplicate SKUs in the workbook are flagged separately. |
| **Import** | Confirm the ready rows. You can pause after the current row and continue in the same admin session. |
| **Done** | Open the created products or download a CSV with the results for each row. |

Preflight lists unsupported files as warnings. Invalid images and folder layouts are reported with the affected SKU and ZIP path when the SKU can be identified. The CSV follows the active admin language.

<details>
<summary><strong>Check results</strong> · Review categories and skipped rows</summary>

![Preflight showing five ready products, eight skipped products and the excluded outer folder](docs/screenshots/check-result.png)

</details>

<details>
<summary><strong>Pause and resume</strong> · Continue the remaining rows</summary>

![Paused import showing progress, row statuses and the Continue import button](docs/screenshots/importing.png)

</details>

<details>
<summary><strong>Import finished</strong> · View products and download the CSV</summary>

![Completed import showing five created products, eight skipped products and the CSV download button](docs/screenshots/finished.png)

</details>

## Prepare the files

### Excel workbook

The importer reads the first worksheet of an `.xlsx` file.

| Columns | Names |
| --- | --- |
| Required | `sku`, `title`, `price`, `description` |
| Optional | `quantity`, `status`, `meta_title` |
| Variants | `variant_name_1`, `variant_value_1`, `variant_price_1`, with further groups numbered `_2`, `_3` and so on. A single unnumbered group also works. |

Format SKUs with leading zeros as text in Excel. Variant values and price adjustments are matched by position. For example, `S, M, L` with `0, 0, -2` reduces the price for `L` by 2. A blank adjustment defaults to zero.

### Image ZIP

Use one folder per SKU, under `Category/SKU/image.jpg` or `Parent/Child/SKU/image.jpg`. Filenames are sorted naturally, so `1.jpg`, `2.jpg` and `10.jpg` appear in that order. The first image becomes the main image.

If a single outer folder wraps the ZIP, select **Ignore the outer ZIP folder for categories**. With `products03/Coffee Grinder/CG01/1.jpg`, this creates the **Coffee Grinder** category and saves the image at `image/catalog/nothiz_product_import/products03/Coffee Grinder/CG01/1.jpg`. You can toggle the option during preflight. If the ZIP has no shared outer folder above the category folders, preflight reports this and uses the default category rules.

- JPG and PNG are supported. WebP requires OpenCart 3.0.3.9 or later and WebP support in PHP GD.
- The importer checks the workbook first. You can omit the ZIP when every product is already in the catalog.
- A saved file is reused when its path and content both match. A file at the same path with different content is reported as a conflict.
- Matching archives can be reused for up to 72 hours, with a cache of up to three ZIPs. Preflight still checks the images against the current catalog paths.

## Requirements and limits

- **OpenCart 3.x** and **PHP 7.3+** with ZIP, DOM, GD and mbstring.
- An admin user group with access and modify permissions for the module. Products are imported into the default store.
- Writable upload and image directories. The image filesystem must support PHP hard links so new files can be saved without overwriting existing ones.

| Item | Limit |
| --- | ---: |
| Excel workbook | 24 MiB |
| Image ZIP | 320 MiB |
| Uncompressed ZIP | 768 MiB |
| Single image | 15 MiB |

The browser uploads in chunks of up to 4 MiB, with up to three parallel image requests. It reduces the chunk size when the server returns HTTP 413. ZIP preflight runs in batches and can resume in the same admin session.

Jobs and cached ZIPs are stored under OpenCart's `DIR_UPLOAD`. The plugin creates Apache protection files for those directories. On Nginx or when Apache `AllowOverride` is disabled, move the OpenCart storage directory outside the web root or block its public URL in the server configuration.

## Build and release

Run `python build.py` to build `nothiz_product_import_oc3.ocmod.zip` locally. Pass an output path as the first argument to build it elsewhere.

GitHub Actions runs all PHP tests on branch pushes. To publish a new version, push a new `vX.Y.Z` tag. If the tests pass, the workflow builds the ZIP and uploads it to Releases. [GitHub Actions](https://github.com/NothiZ/opencart3-product-import/actions)

## Roadmap

- [ ] Add SEO URLs during import.
- [ ] Check available disk space before importing.
- [ ] Add OpenCart 4.x compatibility.

## License

This project uses the MIT license. The bundled js-sha256 library retains its own MIT notice. [Project license](LICENSE) · [js-sha256 license](upload/admin/view/javascript/nothiz/LICENSE.txt)
