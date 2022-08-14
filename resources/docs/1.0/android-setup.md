# Android Setup

---

- [Introduction](#section-1)
- [Setup Instructions](#section-2)

<a name="section-1"></a>
## Introduction
In this article, we are going to set up the TYT App’s initial setup for real-time use cases. 

<a name="section-2"></a>
## Setup Instructions

* Open your project File the Android Studio IDE which is used to create the project and also it is very powerful.
    
Major things to do:
-------------------

1. Change the BASE_URL Variable Presented in the Constants File. 
    * It just updates your server’s primary URL to access all types of API Services From the App to your Server.
    * like this  
        ```java
        public interface URL {
           String BaseURL = "https://admin.tagyourtaxi.com/";
        }
        
        ```
        
2. Change the MQTT_URL value of the Variable which is presented in the SocketHelper File which is presented in the Utils Package.
    * It is used to make a Socket Connection between APP and the Server. So Update this value very carefully with your server’s right IP Address.
    * like this 
        ```java
        public interface URL {
        String SOCKET_URL = "http://3.90.25.20:3001/user";
        }
        
        ```
        

3. Create & configure account for map using Google map & Cloud by following below documents.

        * Google Cloud console link: https://developers.google.com/maps/documentation/android-sdk/cloud-setup
        * firebase setup doc: https://firebase.google.com/docs/android/setup


4. After created & enabled the billing from google cloud & map console
        * We need to create nodes in firebase realtime database, please find the sample json database below.

    - [Sample-json](https://admin.tagyourtaxi.com/firebase-database.json)

        * call_FB_OTP node is used to configure whether the firebase otp should used or dummy otp should use for our testing purpose

5. Download & Paste the google-services.json into the project -> app -> build folder properly to make proper communication from your App which is a client to FireBase.

    Refer below screens for creating project & setup

![image](../../images/android-manual/create-project.png)
![image](../../images/android-manual/firebase-auth.png)

