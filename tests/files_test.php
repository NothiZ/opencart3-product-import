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

function rejects(callable $read, string $message, ?array $issue = null): void {
    try {
        $read();
    } catch (RuntimeException $error) {
        if ($issue !== null) {
            check($error instanceof NothizProductImportIssue && $error->issue() === $issue, $message);
        }
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

    $wrapperName = 'Photos Summer 2026';
    $wrapped = $wrapperName . '/Coffee/Scales/WRAPPER/a.png';
    writeArchive($archive, [
        $wrapped => $png,
        $wrapperName . '/Home/SIMPLE/a.png' => $png,
        $wrapperName . '/MISPLACED/a.png' => $png,
        'notes/readme.txt' => 'unrelated',
    ]);
    $stripped = NothizProductImportFiles::scanImages($archive, null, $imageRoot, true)['index'];
    check(($stripped['outer_folder'] ?? null) === $wrapperName, 'Any shared outer folder should be eligible');
    check($stripped['groups']['WRAPPER']['categories'] === ['Coffee', 'Scales'], 'Only the outer folder should be removed from categories');
    check($stripped['groups']['SIMPLE']['categories'] === ['Home'], 'One-level categories should use the folder below the wrapper');
    check($stripped['groups']['WRAPPER']['entries'] === [$wrapped], 'ZIP image paths must retain the outer folder');
    check($stripped['issues']['MISPLACED'][0]['code'] === 'image_layout', 'Misplaced images should not hide the wrapper');

    $kept = NothizProductImportFiles::scanImages($archive, null, $imageRoot, false)['index'];
    check($kept['groups']['SIMPLE']['categories'] === [$wrapperName, 'Home'], 'Keeping the outer folder should retain the default classification');

    writeArchive($archive, [
        $wrapperName . '/Home/SIMPLE/a.png' => $png,
        'Other/Books/OTHER/a.png' => $png,
    ]);
    $mixed = NothizProductImportFiles::scanImages($archive, null, $imageRoot, true)['index'];
    check(($mixed['outer_folder'] ?? null) === null, 'Multiple roots cannot share a wrapper');
    check($mixed['groups']['SIMPLE']['categories'] === [$wrapperName, 'Home']
        && $mixed['groups']['OTHER']['categories'] === ['Other', 'Books'], 'Multiple roots should use default categories even when requested');

    $red = 'The scanner must support resumable preview';
    check(method_exists(NothizProductImportFiles::class, 'scanImages'), $red);
    $natural = ['products/Home/NAT/item1.PNG', 'products/Home/NAT/item2.png', 'products/Home/NAT/item10.png'];
    $broken = 'products/Home/TRUNC/header.png';
    $brokenPng = substr($png, 0, 33);
    check(getimagesizefromstring($brokenPng) !== false && @imagecreatefromstring($brokenPng) === false, 'Bad fixture must still have a recognizable header');
    $reviewMembers = array_fill_keys(array_reverse($natural), $png) + [
        'products/Home/NAT/README.txt' => 'unrelated',
        'Outside/MISFIT/invalid.gif' => 'unsupported image',
        'products/Home/NAT/spare.gif' => 'unsupported image next to valid images',
        'products/Home/BAD/fake.png' => 'text',
        'products/MISPLACED/a.png' => $png,
        'products/Home/AMB/a.png' => $png,
        'products/Other/AMB/b.png' => $png,
        'products/Home/CASE/A.png' => $png,
        'products/Home/CASE/a.png' => $png,
        $broken => $brokenPng,
    ];
    writeArchive($archive, $reviewMembers);
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, true, 2);
    check(!$scan['done'] && $scan['processed'] === 2 && $scan['index'] === null, 'Initial scan exceeded the checkpoint');
    while (!$scan['done']) {
        $previous = $scan['processed'];
        $scan = NothizProductImportFiles::scanImages($archive, json_decode(json_encode($scan['state']), true), $imageRoot, true, 2);
        check($scan['processed'] > $previous && $scan['processed'] <= $previous + 2, 'Resumed scan exceeded the checkpoint');
    }
    $index = $scan['index'];
    check($scan['total'] === $scan['processed'] && $scan['state'] === null, 'Scan did not complete');
    check($index['outer_folder'] === 'products' && $index['groups']['NAT'] === ['categories' => ['Home'], 'entries' => $natural], 'Products wrapper or natural filename order was incorrect');
    check($index['groups']['TRUNC']['entries'] === [$broken], 'Preview attempted to decode image data');
    check($index['issues']['BAD'][0]['code'] === 'image_type', 'Corrupt header must fail only its matching SKU');
    check($index['issues']['MISPLACED'][0]['code'] === 'image_layout', 'A misplaced image inside products/ must fail only its own SKU');
    check($index['issues']['AMB'][0]['code'] === 'image_folders', 'Ambiguous folders must fail only their matching SKU');
    check($index['issues']['CASE'][0]['code'] === 'image_duplicate', 'Case-colliding paths must fail only their matching SKU');
    check($index['warnings'][0] === ['code' => 'image_ignored', 'params' => ['path' => 'products/Home/NAT/README.txt']], 'Ignored entry warning did not identify ZIP path');
    check(array_column($index['warnings'], 'params') === [
        ['path' => 'products/Home/NAT/README.txt'],
        ['path' => 'Outside/MISFIT/invalid.gif'],
        ['path' => 'products/Home/NAT/spare.gif'],
    ], 'Unsupported images should warn and leave valid JPEG or PNG matches intact');
    $literal = NothizProductImportFiles::scanImages($archive, null, $imageRoot, false)['index'];
    check($literal['groups']['NAT']['categories'] === ['products', 'Home'], 'The preview option should only change category interpretation');

    $prefix = NothizProductImportFiles::IMPORT_IMAGE_PATH . '/';
    $oldFile = $imageRoot . '/' . $prefix . $natural[0];
    mkdir(dirname($oldFile), 0755, true);
    file_put_contents($oldFile, $png);
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, true)['index'];
    check(($scan['groups']['NAT']['reused'] ?? 0) === 1, 'Preview did not report proven identical images');
    $publication = [];
    $paths = NothizProductImportFiles::publishImages($archive, $natural, $imageRoot, $job, 7, $publication);
    check($paths === array_map(function ($path) use ($prefix) { return $prefix . $path; }, $natural), 'Published original paths, names or casing changed');
    check(array_column($publication, 'path') === array_slice($paths, 1), 'Reused image was captured as newly published');
    NothizProductImportFiles::discardPublishedImages($publication, $imageRoot);
    check(is_file($oldFile) && !is_file($imageRoot . '/' . $paths[1]), 'Rollback must remove only newly published files');

    $differentSameSize = $png;
    $differentSameSize[45] = chr(ord($png[45]) ^ 1);
    file_put_contents($oldFile, $differentSameSize);
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, true)['index'];
    check($scan['issues']['NAT'][0] === ['code' => 'image_conflict', 'params' => ['path' => $natural[0]]], 'Preflight must detect a same-size different image');
    rejects(function () use ($archive, $natural, $imageRoot, $job, &$publication) {
        NothizProductImportFiles::publishImages($archive, [$natural[1], $natural[0]], $imageRoot, $job, 7, $publication);
    }, 'Publishing accepted nonidentical destination bytes');
    check(file_get_contents($oldFile) === $differentSameSize && !is_file($imageRoot . '/' . $paths[1]), 'Conflict overwrote an image or failed to roll back its row');

    $corruptFile = $imageRoot . '/' . $prefix . $broken;
    mkdir(dirname($corruptFile), 0755, true);
    file_put_contents($corruptFile, $brokenPng);
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, true)['index'];
    check(!isset($scan['issues']['TRUNC']), 'Preflight should compare equal bytes without GD');
    rejects(function () use ($archive, $broken, $imageRoot, $job, &$publication) {
        NothizProductImportFiles::publishImages($archive, [$broken], $imageRoot, $job, 7, $publication);
    }, 'Publishing reused corrupt image without decoding');
    check(file_get_contents($corruptFile) === $brokenPng, 'The pre-existing corrupt image was removed');

    $exactPath = 'Home/DUP/first.png';
    $otherPath = 'Home/DUP/other.png';
    $upperCase = 'Home/XCASE/file.png';
    $lowerCase = 'home/xcase/file.png';
    writeArchive($archive, [$exactPath => $png, $otherPath => $png, $upperCase => $png, $lowerCase => $png, 'Home/SAFE/ok.png' => $png]);
    $archiveBytes = file_get_contents($archive);
    file_put_contents($archive, str_replace($otherPath, $exactPath, $archiveBytes));
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, false)['index'];
    check($scan['groups']['SAFE']['entries'] === ['Home/SAFE/ok.png'] && $scan['issues']['DUP'][0]['code'] === 'image_duplicate', 'An exact duplicate ZIP member must only fail its SKU');
    check(!isset($scan['groups']['XCASE']) && !isset($scan['groups']['xcase']) && isset($scan['issues']['XCASE'], $scan['issues']['xcase']), 'A case-folded destination collision must invalidate both spellings');

    $oversizedPath = 'Home/LONG/' . str_repeat('a', 230) . '.png';
    $multipleExtensions = 'Home/SUFFIX/picture.php.png';
    $unicodePath = 'Home/UNICODE/Blue chair Ω.png';
    writeArchive($archive, [$oversizedPath => $png, $multipleExtensions => $png, $unicodePath => $png]);
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, false)['index'];
    check(($scan['issues']['LONG'][0]['code'] ?? '') === 'image_path_length', 'Preview accepted a path longer than the OpenCart image column');
    check(($scan['issues']['SUFFIX'][0]['code'] ?? '') === 'image_extension_unsafe', 'Preview accepted an executable secondary filename extension');
    $path = NothizProductImportFiles::publishImages($archive, [$unicodePath], $imageRoot, $job, 7, $publication);
    check($path === [$prefix . $unicodePath] && file_get_contents($imageRoot . '/' . $path[0]) === $png, 'Unicode and spaces in original filename changed');
    foreach ([$oversizedPath, $multipleExtensions] as $invalid) {
        rejects(function () use ($archive, $invalid, $imageRoot, $job, &$publication) {
            NothizProductImportFiles::publishImages($archive, [$invalid], $imageRoot, $job, 7, $publication);
        }, 'Publication accepted an unusable original filename');
    }

    writeArchive($archive, $reviewMembers);
    $zip = new ZipArchive();
    check($zip->open($archive) === true, 'Could not update link fixture');
    $zip->addFromString('Home/SYMLINK/link.png', 'link-target');
    $zip->setExternalAttributesName('Home/SYMLINK/link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    rejects(function () use ($archive, $imageRoot) {
        NothizProductImportFiles::scanImages($archive, null, $imageRoot, true);
    }, 'Archived symlink escaped archive-wide validation');

    $linked = $imageRoot . '/' . $prefix . 'Home/LINK/picture.png';
    mkdir(dirname($linked), 0755, true);
    if (@symlink($corruptFile, $linked)) {
        writeArchive($archive, ['Home/LINK/picture.png' => $brokenPng]);
        $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, false)['index'];
        check($scan['issues']['LINK'][0]['code'] === 'image_conflict' && is_link($linked), 'Preflight followed a destination symlink');
        unlink($linked);
    }

    $longArchivePath = str_repeat(str_repeat('a', 210) . '/', 5) . 'a.png';
    foreach ([
        '../other.png' => 'archive_path',
        'Home/NUL.txt/image.png' => 'archive_path',
        'Home/COM²/image.png' => 'archive_path',
        'Home/CONOUT$/image.png' => 'archive_path',
        'Home/ MATCH/image.png' => 'archive_path',
        'Home/MATCH/image.png ' => 'archive_path',
        'Home/MATCH/question?.png' => 'archive_path',
        $longArchivePath => 'archive_path_length',
    ] as $unsafe => $code) {
        writeArchive($archive, $reviewMembers + [$unsafe => $png]);
        $displayed = strlen($unsafe) > 1024 ? substr($unsafe, 0, 1024) . '…' : $unsafe;
        rejects(function () use ($archive, $imageRoot) {
            NothizProductImportFiles::scanImages($archive, null, $imageRoot, true);
        }, 'Incorrect diagnostic for unsafe ZIP path: ' . $unsafe, ['code' => $code, 'params' => ['path' => $displayed]]);
        rejects(function () use ($archive, $unsafe, $imageRoot, $job, &$publication) {
            NothizProductImportFiles::publishImages($archive, [$unsafe], $imageRoot, $job, 7, $publication);
        }, 'Unsafe file was not rejected before publishing: ' . $unsafe, ['code' => $code, 'params' => ['path' => $displayed]]);
    }
    $large = str_repeat('A', 15 * 1024 * 1024 + 1);
    writeArchive($archive, ['Home/SAFE/ok.png' => $png, 'Home/HUGE/huge.png' => $large, 'ignored.txt' => $large]);
    $scan = NothizProductImportFiles::scanImages($archive, null, $imageRoot, false)['index'];
    check(($scan['issues']['HUGE'][0]['code'] ?? '') === 'archive_entry_size', 'Expanded member limit must identify the oversized image');
    check($scan['groups']['SAFE']['entries'] === ['Home/SAFE/ok.png'] && $scan['warnings'][0]['params']['path'] === 'ignored.txt', 'Oversized member must not invalidate other image matches');
} finally {
    removeFixture($root);
}

echo "File reader tests passed.\n";
