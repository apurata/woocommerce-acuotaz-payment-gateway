# Important considerations
----
## LOCAL
- To clone repository:
```
git clone https://github.com/repo woocommerce-apurata-payment-gateway
```
> Explanation: When you want to add the plugin to wordpress by cloning the repository, you must change the name of the folder from "woocommerce-acuotaz-payment-gateway" to "woocommerce-apurata-payment-gateway"
- Change the owner:
```
sudo chown -R www-data:www-data woocommerce-apurata-payment-gateway
```
## DEPLOY
- Verify permissions:
  - Files:
```
 sudo find woocommerce-apurata-payment-gateway -type f -exec chmod 644 {} \;
```
  - Directories:
```
 sudo find woocommerce-apurata-payment-gateway -type d -exec chmod 755 {} \;
```  
> Explanation: File permissions must be 644 and folders 755.

