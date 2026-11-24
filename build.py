import argparse
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parent
parser = argparse.ArgumentParser(description="Build the OpenCart 3 installer.")
parser.add_argument("output", type=Path, nargs="?", default=root / "nothiz_product_import_oc3.ocmod.zip")
output = parser.parse_args().output.resolve()
paths = sorted(path for path in (root / "upload").rglob("*") if path.is_file())
if not paths:
    raise SystemExit("The OpenCart upload folder is empty.")
with ZipFile(output, "w", ZIP_DEFLATED) as archive:
    archive.write(root / "LICENSE", "LICENSE")
    for path in paths:
        archive.write(path, path.relative_to(root).as_posix())
with ZipFile(output) as archive:
    if archive.testzip() is not None:
        raise SystemExit("The installer ZIP failed its integrity check.")
print(output)
