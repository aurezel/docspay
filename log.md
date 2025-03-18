1.安装插件需注意事项PHP版本为7.4
vi /etc/ssl/openssl.cnf

[openssl_init]
providers = provider_sect
[provider_sect]
default = default_sect
legacy = legacy_sect
[default_sect]
activate = 1
[legacy_sect]
activate = 1

2.将项目拉取到根目录的checkout文件夹中