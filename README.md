# Installation

1. Request credentials, Client ID and Secret Token
2. Download the plugin [click here](https://github.com/apurata/woocommerce-acuotaz-payment-gateway/releases/download/v0.4.2/woocommerce-apurata-payment-gateway.zip)
3. Follow the video steps [here](https://www.youtube.com/watch?v=Ing3DjdB82k)
  
# Important considerations

---

## LOCAL

- To be able to edit the code directly, add your user to the group www-data:

```
sudo adduser myuser www-data
```

> Maybe is necessary close the session to update this change.

- Add write permission to plugins/ directory:

```
sudo chmod g+w plugins/
```

- To clone repository in plugins/ directory:

```
git clone https://github.com/apurata/woocommerce-acuotaz-payment-gateway.git woocommerce-apurata-payment-gateway
```

> When you want to add the plugin to wordpress by cloning the repository, you must change the name of the folder from "woocommerce-acuotaz-payment-gateway" to "woocommerce-apurata-payment-gateway"

- Change the owner:

```
sudo chown -R www-data:www-data woocommerce-apurata-payment-gateway
```

- Change the permissions:

```
sudo chmod -R g+w woocommerce-apurata-payment-gateway
```

### Install PHP5 to test compatibility

- sudo apt install software-properties-common
- sudo add-apt-repository ppa:ondrej/php
- sudo apt update
- sudo apt install -y php5.6
- sudo update-alternatives --config php
- select php 5.6.

  > You can change the php version when you want it.

### Install githooks

- git config core.hooksPath .githooks

  > This step is necessary to check errors before commit. You must not use "--no-verify" without correct before all PHP problems.

## DEPLOY

- Run the "create_release.sh" script.
- Draft a new release.
- The tag name, must start with "v". Like past releases. (e. vx.y.z)
- Write a correct documentation in the release ( This will show in wordpress).
- Add the file woocommerce-apurata-payment-gateway.zip.
- Publish new release.
