# Important considerations
----
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
chmod -R g+w woocommerce-apurata-payment-gateway
```
## DEPLOY
- Run the "create_release.sh" script.
- Draft a new release.
- The tag name, must start with "v". Like past releases.
- Write a correct documentation ( This will show in wordpress).
- Add the file woocommerce-apurata-payment-gateway.zip.
- Publish new release.

