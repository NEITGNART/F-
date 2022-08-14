# Backend Setup

---

- [Introduction](#section-1)
- [Setup Instructions](#section-2)
- [Node.js File Setup](#section-3)

<a name="section-1"></a>
## Introduction
You are almost finished and ready to setup your back end part. once you setup jenkins and taken a build.

<a name="section-2"></a>
## Setup Instructions

* rename the ".env-example" file to ".env"
* Create a database using phpmyadmin
* Setup DB config in .env file
    ```php
    APP_URL=http://tagyourtaxi.com/future/public
    NODE_APP_URL=http://tagyourtaxi.com
    NODE_APP_PORT=3000
    SOCKET_PORT=3001
    ```
<a name="section-3"></a>
## Node.js File Setup

* By run the node.js server file we will be fetch the nearest drivers using geofire in firebase realtime database. so we need to run the node file, please follow the below instructions.

## Steps

* Find the node file by below path
    "project-file/node/server.js"

* run the server file by using pm2. "pm2 start server.js"