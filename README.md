# OpenCart 3 批量商品导入

安装包为 `nothiz_product_import_oc3.ocmod.zip`。在 OpenCart 后台打开 **扩展 → 安装器**并上传安装包。进入 **扩展 → 扩展 → 模块**，安装 **商品导入**，在左侧菜单点击 **商品导入**。英文后台显示为 **Product Import**。

已有旧版时，先在模块列表卸载旧模块，再在安装器移除旧安装记录，上传新版后重新安装模块。模块安装会注册左侧菜单入口。

## 运行条件

- OpenCart 3.x，PHP 7.3 或以上，并启用 ZIP、DOM、GD 和 mbstring。
- 后台用户组需要模块的访问和修改权限。OpenCart 的上传目录以及图片目录需要可写。
- 先检查 Excel，确认有新商品需要图片时才处理 ZIP。图片分块每块最多 4 MiB，最多三个请求并行。收到 HTTP 413 时会自动减小分块。
- 单次导入上限为 Excel 24 MiB、ZIP 320 MiB、ZIP 解压后 768 MiB、单张图片 15 MiB。

## 导入商品

1. 上传 `.xlsx` 商品表。首行包含 `sku`、`title`、`price` 和 `description`。第一个工作表用于导入。SKU 含前导零时，请在 Excel 中将 SKU 列保存为文本。
2. 有新商品需匹配图片时，选择图片 ZIP。使用 `分类/SKU/图片.jpg` 或 `父分类/子分类/SKU/图片.jpg`。支持 JPG、PNG。OpenCart 3.0.3.9 及以上且 GD 支持 WebP 时也可使用 WebP。按文件名排序后第一张作为主图。图片以站点图片目录为根保存，导入后由 OpenCart 管理。
3. 点击 **预检** 查看准备导入、跳过和失败的行，以及分类和图片数。可以下载完整 CSV 报告。
4. 如果后台已经有同一份 ZIP，预检可直接使用。缓存按完整文件内容匹配，最多保留 3 份，每份最长可复用 72 小时。已有 SKU 会跳过并保留其图片。
5. 点击 **确认导入** 执行导入。已经存在的 SKU 会跳过。缺图或字段无效的商品会在预检提示。页面中断后可返回同一后台会话继续未完成的导入。

可选字段为 `quantity`、`status`、`meta_title`。变体继续使用 `variant_name_1`、`variant_value_1`、`variant_price_1` 等顺序编号列，也兼容无编号的单组选项。每个值和加减价按位置对应。例如 `S, M, L` 配 `0, 0, -2` 表示 L 减价 2。变体加减价留空时按 0 处理。

导入到当前 OpenCart 安装的默认店铺。结果可在后台商品列表检查。

## 构建安装包

浏览器端用随安装包提供的 [js-sha256 0.11.1](https://github.com/emn178/js-sha256/tree/v0.11.1) 分片计算文件指纹。

在此目录运行 `python build.py`，生成后台安装器可上传的 `nothiz_product_import_oc3.ocmod.zip`。
