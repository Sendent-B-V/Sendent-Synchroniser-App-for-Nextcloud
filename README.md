# What is Sendent Synchronizer for Nextcloud
Easy to let your users synchronise their Exchange data (calendars, contacts, mails, tasks) with Nextcloud using groups.

# Installation
The easiest way to install this app is by using the [Nextcloud app store](https://apps.nextcloud.com/apps/sendentsynchroniser). If you like to build from source, please continue reading.

## Build from source
Clone this repo into your nextcloud app directory, or [download it as zip](https://github.com/Sendent-B-V/Sendent-App-for-Nextcloud/archive/refs/heads/master.zip) and extract it there, and change into the new directory:
``` console
$ git clone https://github.com//Sendent-B-V/Sendent-Synchroniser-App-for-Nextcloud YOUR_NEXTCLOUD_ROOT/apps/sendentsynchroniser

$ cd YOUR_NEXTCLOUD_ROOT/apps/sendentsynchroniser
```

Next install all dependencies and create a build:

```console
$ make build
```

Now you should be able to enable this app on your Nextcloud app page.

# Questions?
If you have any questions, please contact us at: support@sendent.nl
