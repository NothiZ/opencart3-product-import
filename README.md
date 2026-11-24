# OpenCart 3 批量商品导入

从 [GitHub Releases](https://github.com/NothiZ/opencart3-product-import/releases/latest) 下载 `nothiz_product_import_oc3.ocmod.zip`。在 OpenCart 后台打开 **扩展 → 安装器**并上传安装包。进入 **扩展 → 扩展 → 模块**，安装 **商品导入**，在左侧菜单点击 **商品导入**。英文后台显示为 **Product Import**。

已有旧版时，先在模块列表卸载旧模块，再在安装器移除旧安装记录，上传新版后重新安装模块。模块安装会注册左侧菜单入口。

## 运行条件

- OpenCart 3.x，PHP 7.3 或以上，并启用 ZIP、DOM、GD 和 mbstring。
- 后台用户组需要模块的访问和修改权限。OpenCart 的上传目录和图片目录需要可写，图片目录所在文件系统需支持 PHP 硬链接，以便保存新文件时防止覆盖已有图片。
- 先检查 Excel，确认有新商品需要图片时才处理 ZIP。图片分块每块最多 4 MiB，最多三个请求并行。收到 HTTP 413 时会自动减小分块。
- 单次导入上限为 Excel 24 MiB、ZIP 320 MiB、ZIP 解压后 768 MiB、单张图片 15 MiB。

## 导入商品

1. 上传 `.xlsx` 商品表。首行包含 `sku`、`title`、`price` 和 `description`。第一个工作表用于导入。SKU 含前导零时，请将该列保存为文本。
2. 有新商品时，选择图片 ZIP。图片目录使用 `分类/SKU/图片.jpg` 或 `父分类/子分类/SKU/图片.jpg`。支持 JPG、PNG，以及 OpenCart 3.0.3.9 及以上且 GD 支持的 WebP。文件名按自然顺序排列，例如 `1.jpg`、`2.jpg`、`10.jpg`，第一张作为主图。
3. 点击 **预检**。ZIP 会分批检查目录和图片头，浏览器中断后可在同一后台会话继续预检。如果图片共用一个打包文件夹，可在上传区勾选 **忽略 ZIP 最外层文件夹**，也可在预检页面切换。比如 `8月出货图/咖啡/电子秤/CS01/1.jpg` 会归到 `咖啡 / 电子秤`。ZIP 中的最外层目录不唯一时，预检会提示并使用默认分类。
4. 后台中 SKU 或 Model 匹配的商品会自动跳过。Excel 中后续重复的 SKU 会标为 **表内重复**。不支持的杂文件会列在 ZIP 提示中。坏图和异常目录会标到对应 SKU，详情包含文件路径。可以下载与当前后台语言一致的完整 CSV。
5. 确认导入待处理行。图片完整解码在导入时进行。导入中可以点击 **暂停导入**，处理完当前行后暂停，之后可从同一后台会话继续。数据库导入锁被占用时，尚未导入的行会继续保留为待导入。

新导入图片保存在 `image/catalog/nothiz_product_import/` 下，子目录和文件名保留 ZIP 中的原样。比如 `products/Coffee Scale/CS01/1.jpg` 保存为 `image/catalog/nothiz_product_import/products/Coffee Scale/CS01/1.jpg`，即使选择从分类中忽略该外层文件夹。如果该路径已有完全相同的文件，会直接复用。内容不同会显示冲突和路径，需要更名或调整 ZIP 后重新预检。此前已导入商品继续使用其原有图片路径。

同一份 ZIP 可以从服务器缓存复用，免去再次传输。缓存通过文件内容校验识别，最多保留 3 份，每份最长可复用 72 小时。复用 ZIP 时仍会检查图片和当前后台中的同路径文件。

可选字段为 `quantity`、`status`、`meta_title`。变体继续使用 `variant_name_1`、`variant_value_1`、`variant_price_1` 等顺序编号列，也兼容无编号的单组选项。每个值和加减价按位置对应。例如 `S, M, L` 配 `0, 0, -2` 表示 L 减价 2。变体加减价留空时按 0 处理。

导入到当前 OpenCart 安装的默认店铺。结果可在后台商品列表检查。

## 暂存目录

任务与 ZIP 缓存保存在 OpenCart 的 `DIR_UPLOAD` 下，插件会在各自目录生成拒绝 HTTP 访问的 Apache `.htaccess` 和空的 `index.html`。如果用 Nginx 或 Apache 禁用了 `AllowOverride`，请将 OpenCart 的 storage 目录移出网站根目录，或在服务器配置中阻止访问实际的 storage URL。上传文件和报告通过后台授权接口使用。

## 构建安装包

浏览器端用随安装包提供的 [js-sha256 0.11.1](https://github.com/emn178/js-sha256/tree/v0.11.1) 分片计算文件指纹。

在此目录运行 `python build.py`，生成后台安装器可上传的 `nothiz_product_import_oc3.ocmod.zip`。也可以使用 `python build.py /path/to/nothiz_product_import_oc3.ocmod.zip` 指定输出位置。

## 许可证

项目采用 [MIT 许可证](LICENSE)。随插件提供的第三方库保留各自的许可证。
