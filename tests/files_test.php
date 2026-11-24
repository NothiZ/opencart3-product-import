<?php

$file = __DIR__ . '/../upload/system/library/nothiz_product_import/files.php';
if (!is_file($file)) {
    fwrite(STDERR, "The product import file reader is missing.\n");
    exit(1);
}
require $file;

function check($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $read, string $message): void {
    try {
        $read();
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException($message);
}

function writeArchive(string $path, array $members): void {
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Could not create fixture');
    foreach ($members as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    check($zip->close(), 'Could not close fixture');
}

function removeFixture(string $path): void {
    if (!is_dir($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) {
        removeFixture($entry->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/nothiz-files-' . bin2hex(random_bytes(8));
mkdir($root);
try {
    $sheetNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $officeNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $relsNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $workbook = $root . '/products.xlsx';
    $parts = [
        'xl/workbook.xml' => '<workbook xmlns="' . $sheetNamespace . '" xmlns:r="' . $officeNamespace . '"><sheets>'
            . '<sheet name="Products" sheetId="2" r:id="sheet"/><sheet name=" Other" sheetId="1" r:id="other"/>'
            . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="' . $relsNamespace . '">'
            . '<Relationship Id="other" Type="' . $officeNamespace . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="sheet" Type="' . $officeNamespace . '/worksheet" Target="/xl/worksheets/sheet2.xml"/>'
            . '<Relationship Id="strings" Type="' . $officeNamespace . '/sharedStrings" Target="strings.xml"/>'
            . '</Relationships>',
        'xl/strings.xml' => '<sst xmlns="' . $sheetNamespace . '"><si><t>SKU</t></si>'
            . '<si><r><t>Rich </t></r><r><t>title</t></r><rPh sb="0" eb="1"><t>ignored</t></rPh></si></sst>',
        'xl/worksheets/sheet2.xml' => '<worksheet xmlns="' . $sheetNamespace . '"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c>'
            . '<c r="C1" t="inlineStr"><is><t>Title</t></is></c>'
            . '<c r="D1" t="inlineStr"><is><t>Price</t></is></c>'
            . '<c r="E1" t="inlineStr"><is><t>Description</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>0007</t></is></c>'
            . '<c r="C2" t="s"><v>1</v></c><c r="D2"><f>12+1</f><v>13.50</v></c></row>'
            . '<row r="4"><c r="A4" t="str"><v>SKU-8</v></c><c r="E4" t="inlineStr"><is><t>A&#10;B</t></is></c></row>'
            . '<row r="5"><c r="E5"/></row></sheetData></worksheet>',
    ];
    writeArchive($workbook, $parts);
    check(NothizProductImportFiles::readWorkbook($workbook) === [
        ['row' => 2, 'data' => ['sku' => '0007', 'title' => 'Rich title', 'price' => '13.50', 'description' => '']],
        ['row' => 4, 'data' => ['sku' => 'SKU-8', 'title' => '', 'price' => '', 'description' => "A\nB"]],
    ], 'Incorrect relationship, sparse cell, rich text, numeric, or blank row handling');

    $parts['xl/worksheets/sheet2.xml'] = str_replace('<t>Price</t>', '<t>Weight</t>', $parts['xl/worksheets/sheet2.xml']);
    writeArchive($workbook, $parts);
    rejects(function () use ($workbook) {
        NothizProductImportFiles::readWorkbook($workbook);
    }, 'Required price header was accepted as missing');

    // Sparse cells still produce a dense associative row, so cap the expanded output shape.
    $wideHeader = '';
    for ($column = 1; $column <= 100; $column++) {
        $name = ['sku', 'title', 'price', 'description'][$column - 1] ?? ('extra_' . $column);
        $wideHeader .= '<c t="inlineStr"><is><t>' . $name . '</t></is></c>';
    }
    $wideRows = str_repeat('<row><c t="inlineStr"><is><t>SKU</t></is></c></row>', 2501);
    $parts['xl/worksheets/sheet2.xml'] = '<worksheet xmlns="' . $sheetNamespace . '"><sheetData><row>'
        . $wideHeader . '</row>' . $wideRows . '</sheetData></worksheet>';
    writeArchive($workbook, $parts);
    rejects(function () use ($workbook) {
        NothizProductImportFiles::readWorkbook($workbook);
    }, 'Sparse worksheet exceeded the maximum expanded cell count');

    $parts['xl/workbook.xml'] = '<!DOCTYPE workbook [<!ENTITY value "example">]>' . $parts['xl/workbook.xml'];
    writeArchive($workbook, $parts);
    rejects(function () use ($workbook) {
        NothizProductImportFiles::readWorkbook($workbook);
    }, 'XML with a DOCTYPE was accepted');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    $archive = $root . '/images.zip';
    $members = [
        'catalog/products/Home/0007/b.png' => $png,
        'catalog/products/Home/0007/A.png' => $png,
        'catalog/products/Home/Lighting/SKU-8/a.png' => $png,
        'catalog/products/.DS_Store' => 'metadata',
    ];
    writeArchive($archive, $members);
    $images = NothizProductImportFiles::inspectImages($archive);
    check($images['0007'] === [
        'categories' => ['Home'],
        'entries' => ['catalog/products/Home/0007/A.png', 'catalog/products/Home/0007/b.png'],
    ], 'Incorrect SKU folder or image order');
    check($images['SKU-8']['categories'] === ['Home', 'Lighting'], 'Incorrect nested categories');

    $imageRoot = $root . '/image';
    mkdir($imageRoot);
    $job = str_repeat('a', 32);
    $destination = 'catalog/nothiz_product_import/' . $job . '/7/';
    $paths = NothizProductImportFiles::publishImages($archive, $images['0007']['entries'], $imageRoot, $job, 7);
    check($paths === [$destination . '1.png', $destination . '2.png'], 'Incorrect published image paths');
    check(file_get_contents($imageRoot . '/' . $paths[0]) === $png, 'Image bytes changed while publishing');
    rejects(function () use ($archive, $images, $imageRoot, $job) {
        NothizProductImportFiles::publishImages($archive, $images['0007']['entries'], $imageRoot, $job, 7);
    }, 'Publishing accepted an existing destination');
    check(file_get_contents($imageRoot . '/' . $paths[0]) === $png, 'Retry removed an existing file');

    check(method_exists(NothizProductImportFiles::class, 'discardRowImages'), 'Interrupted rows cannot clean up stale images');
    $extra = $imageRoot . '/' . $destination . 'notes.txt';
    file_put_contents($extra, 'unrelated');
    rejects(function () use ($imageRoot, $job) {
        NothizProductImportFiles::discardRowImages($imageRoot, $job, 7);
    }, 'Stale image cleanup accepted an unrelated file');
    check(is_file($extra) && is_file($imageRoot . '/' . $paths[0]), 'Stale image cleanup touched a mixed folder');
    unlink($extra);

    NothizProductImportFiles::discardRowImages($imageRoot, $job, 7);
    NothizProductImportFiles::discardRowImages($imageRoot, $job, 7);
    check(!is_dir($imageRoot . '/' . $destination), 'Stale image folder survived cleanup');
    NothizProductImportFiles::publishImages($archive, $images['0007']['entries'], $imageRoot, $job, 7);
    NothizProductImportFiles::discardImages($paths, $imageRoot);
    check(!is_file($imageRoot . '/' . $paths[0]), 'Rollback did not discard published image');

    rejects(function () use ($archive, $images, $imageRoot, $job) {
        NothizProductImportFiles::publishImages($archive, [$images['0007']['entries'][0], 'missing.png'], $imageRoot, $job, 8);
    }, 'Missing archive member was accepted while publishing');
    check(!is_file($imageRoot . '/' . str_replace('/7/', '/8/', $paths[0])), 'Partial publish left an image');

    foreach ([
        'catalog/products/Other/0007/a.png' => $png,
        '../other.png' => $png,
        'catalog/products/Home/BAD/fake.png' => 'text',
        'catalog/products/Home/BAD/truncated.png' => substr($png, 0, 25),
        'catalog/products/Home/BAD/script.php' => '<?php echo 1;',
    ] as $name => $content) {
        writeArchive($archive, $members + [$name => $content]);
        rejects(function () use ($archive) {
            NothizProductImportFiles::inspectImages($archive);
        }, 'Invalid ZIP member accepted: ' . $name);
    }
} finally {
    removeFixture($root);
}

echo "File reader tests passed.\n";
