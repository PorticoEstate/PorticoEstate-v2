If Oracle-support is needed, you need to install the Oracle Instant Client.
Get this files from https://www.oracle.com/database/technologies/instant-client/linux-x86-64-downloads.html

instantclient-basic-linux.x64-12.2.0.1.0.zip
instantclient-sdk-linux.x64-12.2.0.1.0.zip

To build the Docker image - issue the following command:

```
export INSTALL_ORACLE=true
docker compose build
```