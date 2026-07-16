# docker/certs/

社内プロキシやウイルス対策ソフト (Norton等) がTLS通信を代理検査 (SSLスキャン) する環境では、
Dockerコンテナ内から `composer install` / `npm install` を実行する際に
証明書検証エラー (`unable to get local issuer certificate` など) が発生することがあります。

これは、ホストOS (Windows) 側では当該ソフトのルート証明書が信頼済みなのに対し、
Dockerコンテナ (Linux) 側にはその証明書が入っていないために起こります。

## 対処法

1. **推奨**: セキュリティソフト側でDocker Desktop / WSLの通信をSSLスキャン対象から除外する。
2. それが難しい場合は、該当するルート証明書 (PEM形式) をこのディレクトリに配置する。
   - 例: `docker/certs/extra-ca.pem`
   - `backend/Dockerfile` / `analyzer/Dockerfile` はビルド時にこのディレクトリの
     `*.pem` / `*.crt` を自動的に信頼済みルート証明書として追加する。
   - 何も置かなければ何も起こらない (通常の環境では不要)。

このディレクトリ配下の実際の証明書ファイル (`*.pem`, `*.crt`) は `.gitignore` で
除外されており、開発者ごとにローカルで用意するものであってリポジトリにはコミットしない。
