## Default Permission

Default permission set for the NativeBlade payments plugin.
Allows querying products, starting a purchase, restoring previous
purchases, reading subscription status and draining purchase outcomes
settled outside a purchase call.

#### This default permission set includes the following:

- `allow-query-products`
- `allow-purchase`
- `allow-restore-purchases`
- `allow-subscription-status`
- `allow-drain-pending`

## Permission Table

<table>
<tr>
<th>Identifier</th>
<th>Description</th>
</tr>


<tr>
<td>

`nativeblade-payments:allow-drain-pending`

</td>
<td>

Enables the drain_pending command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:deny-drain-pending`

</td>
<td>

Denies the drain_pending command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:allow-purchase`

</td>
<td>

Enables the purchase command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:deny-purchase`

</td>
<td>

Denies the purchase command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:allow-query-products`

</td>
<td>

Enables the query_products command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:deny-query-products`

</td>
<td>

Denies the query_products command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:allow-restore-purchases`

</td>
<td>

Enables the restore_purchases command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:deny-restore-purchases`

</td>
<td>

Denies the restore_purchases command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:allow-subscription-status`

</td>
<td>

Enables the subscription_status command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-payments:deny-subscription-status`

</td>
<td>

Denies the subscription_status command without any pre-configured scope.

</td>
</tr>
</table>
