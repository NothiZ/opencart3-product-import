<h1 align="center">OpenCart 3 批量商品导入</h1>

<p align="center">在 OpenCart 后台通过 Excel 商品表和图片 ZIP 批量导入商品。</p>

<p align="center">
  <a href="https://github.com/NothiZ/opencart3-product-import/actions/workflows/test-and-release.yml"><img alt="PHP 测试状态" src="https://github.com/NothiZ/opencart3-product-import/actions/workflows/test-and-release.yml/badge.svg"></a>
</p>

<p align="center">
  <a href="https://github.com/NothiZ/opencart3-product-import/releases/latest">下载最新安装包</a> ·
  <a href="./README.md">English</a> ·
  <a href="./LICENSE">MIT 许可证</a>
</p>

![OpenCart 上传界面，可选择 Excel、图片 ZIP 以及是否忽略最外层文件夹](docs/screenshots/upload.png)

## 功能特点

- **导入前预检。** 表格中的每行都会标为待导入、已跳过、表内重复或失败。确认导入后才会写入商品目录。
- **从 ZIP 导入图片。** 目录决定商品分类，文件名决定图片顺序。排序后的第一张图片用作主图。
- **支持商品变体。** 尺寸、颜色等变体会生成 OpenCart 商品选项。
- **支持暂停与续跑。** 当前行处理完成后可暂停。继续导入时会跳过已经创建的商品。
- **逐行查看结果。** 每行都显示处理状态，需要调整时会给出具体提示。也可下载完整的 CSV。

## 快速安装

1. 在最新版本的 **Assets** 中下载 `nothiz_product_import_oc3.ocmod.zip`。[GitHub Releases](https://github.com/NothiZ/opencart3-product-import/releases/latest)
2. 在 OpenCart 后台打开 **扩展 → 安装器**，上传安装包。
3. 进入 **扩展 → 扩展**，选择 **模块**，安装 **商品导入**，然后从左侧菜单打开。英文后台显示为 **Product Import**。

升级时，先卸载旧模块并移除旧的安装记录，再上传新版安装包并重新安装。卸载模块会移除左侧菜单入口，已导入的商品、分类和图片会保留在店铺中。

## 导入流程

| 步骤 | 操作 |
| --- | --- |
| **上传文件** | 选择 Excel 商品表。有新商品时，再选择图片 ZIP。 |
| **预检** | 核对分类与每行状态。后台已有相同 SKU 或 Model 的商品会跳过，表内重复的 SKU 会单独标记。 |
| **导入** | 点击导入按钮并确认。选择 **暂停导入** 后，插件会在当前行处理完毕时暂停。在同一后台会话中点击 **继续导入** 即可续跑。 |
| **完成** | 查看新创建的商品，下载逐行结果 CSV，或开始导入下一批。 |

预览显示当前筛选条件下的前 100 行。CSV 包含全部行，详情使用当前后台语言。

<details>
<summary><strong>预检结果</strong> · 查看分类和跳过的行</summary>

![预检界面显示 5 条待导入、8 条已跳过，以及已忽略的外层文件夹](docs/screenshots/check-result.png)

</details>

<details>
<summary><strong>暂停与续跑</strong> · 继续处理剩余行</summary>

![导入暂停界面显示处理进度、商品状态和继续导入按钮](docs/screenshots/importing.png)

</details>

<details>
<summary><strong>导入完成</strong> · 查看商品并下载 CSV</summary>

![完成界面显示 5 件商品已创建、8 条已跳过，以及 CSV 下载按钮](docs/screenshots/finished.png)

</details>

## 准备导入文件

### Excel 商品表

使用 `.xlsx` 文件。插件读取第一张工作表，第一行填写列名。

| 列类型 | 列名 |
| --- | --- |
| 必填 | `sku`、`title`、`price`、`description` |
| 可选 | `quantity`、`status`、`meta_title` |
| 变体 | `variant_name_1`、`variant_value_1`、`variant_price_1`。多组依次用 `_2`、`_3` 等编号，也支持无编号的单组变体。 |

下面是一行完整的商品示例。

| sku | title | price | description | quantity | status | variant_name_1 | variant_value_1 | variant_price_1 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CG01 | Manual Coffee Grinder | 39.90 | Stainless steel burrs. | 50 | 1 | Color | Black, Silver | 0, 2 |

- **价格。** 使用 `39.90` 这样的纯数字。货币符号和千位分隔符会被判为无效。
- **描述。** 按纯文本读取。空白行分段，单次换行保留为换行。HTML 标签会按原文本显示。
- **库存和状态。** `quantity` 填整数。`status` 支持 `1`、`0`、`true`、`false`、`yes`、`no`。
- **变体。** 值之间可以用逗号、竖线或换行分隔。值与价格调整按位置对应。上例中 `Black` 加价 0，`Silver` 加价 2。`S, M, L` 配 `0, 0, -2` 时，`L` 减价 2。价格调整留空时按 0 处理。
- **前导零。** 如果 SKU 以 0 开头，请在录入前将该列设为文本，以保留完整 SKU。

### 图片 ZIP

以 SKU 命名每个商品的图片目录，再将该目录放入对应的分类目录。

```text
images.zip
├── Coffee Grinder/                 分类
│   ├── CG01/                       SKU
│   │   ├── 1.jpg                   主图
│   │   └── 2.jpg
│   └── CG02/
│       └── 1.png
└── Kitchen/Coffee Scale/CS01/1.jpg 父分类 / 子分类 / SKU / 图片
```

- 支持 `分类/SKU/图片.jpg` 或 `父分类/子分类/SKU/图片.jpg`。目录名须与表格中的 SKU 一致，每个 SKU 使用一个图片目录。
- 文件按自然顺序排列，例如 `1.jpg`、`2.jpg`、`10.jpg`。第一张作为主图。
- 支持 JPG 和 PNG。WebP 需要 OpenCart 3.0.3.9 或以上版本，并且 PHP GD 支持 WebP。
- 非图片文件以及 macOS、Windows 生成的系统文件会被忽略，并在预检中列为提示。
- 插件先检查商品表。如果后台已存在所有商品，可以省略图片 ZIP。

**最外层文件夹。** 如果整个 ZIP 被包在同一个打包目录下，可以勾选 **忽略 ZIP 最外层文件夹（仅影响分类）**。例如 `products03/Coffee Grinder/CG01/1.jpg` 中的商品会归到 **Coffee Grinder**，图片保存到 `image/catalog/nothiz_product_import/products03/Coffee Grinder/CG01/1.jpg`。预检时也可以切换该选项。如果分类目录之上没有共同的外层文件夹，预检会提示并沿用默认分类。

**保存图片。** 图片保存在 `image/catalog/nothiz_product_import/` 下，并保留 ZIP 中的目录和文件名。同路径、同内容的图片会复用。同路径的内容不同时，该行会报冲突并保留已有图片。在 OpenCart 删除商品后，图片文件仍保留。

**复用 ZIP。** 服务器最多缓存 3 份 ZIP，每份可保留最多 72 小时。再次选择同一份 ZIP 时可跳过上传。预检仍会核对图片与当前后台中的文件。

## 导入会创建什么

每个新商品会写入以下内容。

- 将表格中的 SKU 同时保存到 **SKU** 和 **Model**，并将商品加入默认店铺。
- 保存商品名称和 Meta 标题。`meta_title` 留空时使用商品名称。每个启用的商品内容语言都使用同一份文本。
- 按目录名设置商品分类。同一父分类下的同名分类会复用，缺少的分类会创建并启用。
- 从 ZIP 保存主图和相册。
- 将每组变体创建为商品选项。已有同名选项会复用。变体值也会写入 **Specifications** 属性组。

| 设置 | 默认值 |
| --- | --- |
| 库存 | `quantity` 留空时为 `1000` |
| 状态 | `status` 留空时启用，商品会立即上架 |
| 配送 | 需要配送 |
| 税类、重量、制造商 | 无、`0`、无 |
| 商品标签、Meta 描述、Meta 关键词 | 留空 |

需要调整默认值时，可在表格填写 `quantity` 和 `status`。如果要先检查商品再上架，请将 `status` 设为 `0`。

## 当前限制

- 已有商品会按 SKU 或 Model 跳过。批量更新已有商品不在当前导入范围内。
- 商品统一导入到默认店铺。
- 目前使用 OpenCart 默认 URL，可在后台手动添加商品和新分类的 SEO 关键词。
- 当前表格只读取上述列。制造商、重量、尺寸、特价、商品标签和 Meta 描述等需在后台维护。
- 商品表只读取第一张工作表。
- 当前兼容 OpenCart 3.x。OpenCart 4.x 适配见待更新。

## 运行条件与限制

- **OpenCart 3.x** 和 **PHP 7.3+**，启用 ZIP、DOM、GD 和 mbstring 扩展。
- 后台用户组需要模块的访问和修改权限。
- 上传目录与图片目录需要可写。图片目录所在的文件系统需支持 PHP 硬链接（`link()`），以便安全保存新图。

| 项目 | 上限 |
| --- | ---: |
| Excel 商品表 | 24 MiB |
| 每份商品表的商品行数 | 10,000 |
| 图片 ZIP | 320 MiB |
| 每份 ZIP 的条目数 | 10,000 |
| ZIP 解压后 | 768 MiB |
| 单张图片 | 15 MiB |
| 每个 SKU 的图片数 | 100 |
| `sku`、选项名称 | 64 字符 |
| `title`、`meta_title`、分类目录名 | 255 字符 |
| 选项值 | 128 字符 |
| 保存后的图片路径 | 255 字符 |

浏览器分块上传，每块最多 4 MiB，图片最多三个请求并行。服务器返回 HTTP 413 时会自动缩小分块。ZIP 预检分批执行，可以在同一后台会话中继续。未完成的预检或导入超过 24 小时有效期后会过期，需要重新上传文件。

任务与缓存 ZIP 存放在 OpenCart 的 `DIR_UPLOAD` 下，插件会为这些目录生成 Apache 访问保护文件。使用 Nginx 或禁用了 Apache `AllowOverride` 时，请将 OpenCart 的 storage 目录移出网站根目录，或在服务器配置中阻止外部访问其 URL。

## 常见问题

| 现象 | 原因和处理方法 |
| --- | --- |
| 预检通过，但每行都提示 **无法保存图片** | 检查 PHP 的 `disable_functions` 是否禁用了 `link`，以及图片目录所在的文件系统是否支持硬链接。可运行 `php -r 'var_dump(function_exists("link"));'` 检查 PHP 函数是否可用，预期输出为 `bool(true)`。必要时联系主机服务商。 |
| **现有图片路径冲突** | 同路径已有不同内容的文件。删除商品后再用同名的修改版图片导入时也可能出现。可以重命名新图片或目录。确认旧图已无商品引用后，也可以把它从 `image/catalog/nothiz_product_import/` 移走。 |
| **未找到与此 SKU 唯一对应的图片目录** | 检查图片目录是否存在、目录名是否与表格 SKU 一致，以及同一个 SKU 是否出现在多个目录。 |
| **目录层级不符合要求** | 请使用 `分类/SKU/图片.jpg` 或 `父分类/子分类/SKU/图片.jpg`。如果整个 ZIP 共用一个外层打包目录，可勾选 **忽略 ZIP 最外层文件夹**。 |
| **价格无效** | 价格为空，或包含文字、货币符号、千位分隔符。请填写 `39.90` 这样的纯数字。 |
| SKU 的前导零丢失 | 将 Excel 的 SKU 列设为文本后，再录入原始 SKU。 |
| **服务器连 16 KiB 的分块请求也拒绝了** | 请联系主机服务商检查并提高单次请求大小限制。 |
| **无法获取商品导入锁** | 请联系主机服务商检查 MySQL 命名锁是否可用。 |
| **预检结果已过期或不可用** | 预检超过 24 小时，或当前使用了不同的后台会话。请重新上传文件。 |

其他错误可在 OpenCart 错误日志中查看。

## 构建与发布

运行 `python build.py`，在本地生成 `nothiz_product_import_oc3.ocmod.zip`。也可以在第一个参数中指定输出路径。

每次推送分支都会由 GitHub Actions 运行全部 PHP 测试。推送新的 `vX.Y.Z` 标签后，测试通过时工作流会打包 ZIP 并发布。[GitHub Releases](https://github.com/NothiZ/opencart3-product-import/releases)

## 待更新

- [ ] 导入时添加 SEO URL。
- [ ] 导入前检查可用磁盘空间。
- [ ] 适配 OpenCart 4.x。

## 许可证

项目采用 MIT 许可证。随插件提供的 js-sha256 库保留其原有的 MIT 许可声明。[项目许可证](LICENSE) · [js-sha256 许可证](upload/admin/view/javascript/nothiz/LICENSE.txt)
