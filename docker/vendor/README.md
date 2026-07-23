# docker/vendor/

Dockerビルド時にネットワーク経由での取得が不安定な依存物をここにベンダリングする。

## phpredis-<version>.tar.gz

`pecl install redis` は pecl.php.net の証明書チェーンが不安定で失敗することがあるため、
[phpredis](https://github.com/phpredis/phpredis) のソースtarballをここに置き、
`backend/Dockerfile` がGitHubへの再アクセスなしにビルドできるようにしている。

### バージョンを上げる手順

```bash
curl -fsSL -o docker/vendor/phpredis-<new-version>.tar.gz \
  https://github.com/phpredis/phpredis/archive/refs/tags/<new-version>.tar.gz
```

その後、`backend/Dockerfile` の `ARG PHPREDIS_VERSION=<new-version>` を更新する。
