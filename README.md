# PageLike

给 MediaWiki 页面加一个简单的点赞按钮。

PageLike 会记录登录用户对页面的点赞状态，并提供点赞数和排行榜 API。扩展自带一个红心按钮，也可以关闭默认界面，换成站点自己的样式。

## 功能

- 登录用户可以点赞或取消点赞，重复请求不会产生重复记录
- 匿名用户和临时账户可以查看点赞数，但不能点赞
- 安装 Echo 时，页面获得新点赞会通知页面创建者（不通知点赞者本人或匿名创建者）
- 可选的 7 天、30 天和全部时间排行榜（默认关闭）
- 可按命名空间启用，并可在单个页面上关闭
- 提供 Action API，方便小工具、模板和自定义前端调用
- 页面删除时自动清理对应的点赞记录
- 不公开点赞用户列表，也不会为普通点赞写公共日志

## 环境要求

- MediaWiki 1.45.1
- PHP 8.2 或 8.3
- MySQL 或 MariaDB（目前主要在 MariaDB 10.11 上测试）

Echo 是可选依赖；只有创建者通知需要 Echo。没有 Echo 时，点赞、计数、按钮和排行榜仍可正常使用。

## 安装

在 MediaWiki 根目录执行：

```sh
cd extensions
git clone https://github.com/YukkuriB/PageLike.git
```

然后在 `LocalSettings.php` 中加载 PageLike：

```php
wfLoadExtension( 'PageLike' );
```

如果需要创建者获赞通知，请安装与 MediaWiki 1.45 兼容的 Echo，并在 PageLike 之前加载：

```php
wfLoadExtension( 'Echo' );
wfLoadExtension( 'PageLike' );
```

最后运行数据库更新：

```sh
php maintenance/run.php update --quick
```

安装完成后，主命名空间的页面底部会出现点赞按钮。

## 配置

PageLike 默认即可使用。下面列出的是内置默认值，只有需要修改时才需要写进 `LocalSettings.php`：

```php
$wgPageLikeEnabled = true;
$wgPageLikeEnableWrites = true;
$wgPageLikeEnableRanking = false;
$wgPageLikeShowDefaultButton = true;
$wgPageLikeAllowedNamespaces = [ NS_MAIN ];
```

普通登录用户默认拥有 `pagelike` 权限。如果只想让特定用户组点赞，可以覆盖组权限：

```php
$wgGroupPermissions['user']['pagelike'] = false;
$wgGroupPermissions['sysop']['pagelike'] = true;
```

排行榜默认关闭。确认站点已配置能跨请求复用的持久化主缓存，并用代表性数据评估聚合查询负载后，可以启用：

```php
$wgPageLikeEnableRanking = true;
```

安装 Echo 后，页面创建者的站内通知默认开启，邮件通知默认关闭；用户可以在通知偏好中分别调整。只有真正新增的点赞会触发通知，重复点赞请求和取消点赞都不会触发。未安装 Echo 时会安全跳过通知，不影响其他功能。

排行榜会把原始候选结果缓存最多 60 秒。在 `CACHE_NONE` 下，该缓存无法跨请求命中，每次排行榜 API 请求都会重新执行计数、分组和排序聚合。公开站点在没有持久化主缓存时应保持排行榜关闭。基础点赞在 `CACHE_NONE` 下仍可使用，但 MediaWiki 标准 RateLimit 也无法可靠工作。

## 在单个页面上关闭点赞

在页面源码中加入以下任一标记：

```text
__NOPAGELIKE__
__关闭点赞__
```

标记也会通过模板转入。如果刚添加标记但页面仍显示按钮，清除该页面的解析器缓存即可。

## API

读取页面状态：

```text
GET api.php?action=query&prop=pagelikeinfo&pageids=123&format=json&formatversion=2
```

响应中的 `liked` 表示当前用户是否已点赞，`count` 是点赞数，`canlike` 表示当前用户是否可以操作。

点赞或取消点赞需要 CSRF token：

```text
POST api.php
action=pagelike&pageid=123&set=1&token=<csrf>&format=json&formatversion=2
```

`set=1` 为点赞，`set=0` 为取消点赞。

启用排行榜后，可以这样查询：

```text
GET api.php?action=query&list=pagelikerank&plrperiod=7d&plrlimit=10&format=json&formatversion=2
```

`plrperiod` 可选 `7d`、`30d` 或 `all`，`plrlimit` 最大为 100。在有效的持久化主缓存下，排行榜原始候选结果最多缓存 60 秒。

## 自定义外观

默认按钮可以通过 CSS 变量调整，例如在 `MediaWiki:Common.css` 中加入：

```css
.ext-pagelike {
	--pagelike-active-color: #ff4d78;
	--pagelike-background: color-mix(in srgb, currentcolor 8%, transparent);
	--pagelike-radius: 0.75rem;
}
```

如果想完全使用自己的按钮，可以关闭默认界面：

```php
$wgPageLikeShowDefaultButton = false;
```

扩展仍会输出 `.ext-pagelike` 挂载点。点赞状态改变后还会触发 `ext.pageLike.changed` hook，事件对象包含 `root`、`pageId`、`liked` 和 `count`。

## 维护

清理已经不存在的页面或用户所留下的记录：

```sh
php extensions/PageLike/maintenance/PrunePageLikes.php --dry-run
php extensions/PageLike/maintenance/PrunePageLikes.php
```

删除指定用户的全部点赞记录：

```sh
php extensions/PageLike/maintenance/DeleteUserLikes.php --user-id=123 --dry-run
php extensions/PageLike/maintenance/DeleteUserLikes.php --user-id=123
```

两个脚本都支持 `--batch-size`。建议先使用 `--dry-run` 查看影响范围。

## 开发与测试

测试需要一个包含 Composer 开发依赖的 MediaWiki 源码树，并将本仓库放在 `extensions/PageLike`：

```sh
php tests/phpunit/phpunit.php extensions/PageLike/tests/phpunit/
php maintenance/run.php validateRegistrationFile extensions/PageLike/extension.json
php maintenance/run.php generateSchemaSql --validate \
  --json extensions/PageLike/sql/tables.json --type mysql
```

前端测试可在本地测试配置中启用 `$wgEnableJavaScriptTest = true`，然后访问 `Special:JavaScriptTest?component=PageLike`。

## 许可证

[GPL-2.0-or-later](LICENSE)
