## Default Permission

Default permission set for the NativeBlade push notifications plugin.
Allows querying the device token, requesting notification permission,
and draining pushes that were buffered during cold start.

#### This default permission set includes the following:

- `allow-get-token`
- `allow-request-permission`
- `allow-drain-pending`
- `allow-notify`
- `allow-cancel`
- `allow-cancel-all`

## Permission Table

<table>
<tr>
<th>Identifier</th>
<th>Description</th>
</tr>


<tr>
<td>

`nativeblade-push:allow-cancel`

</td>
<td>

Enables the cancel command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:deny-cancel`

</td>
<td>

Denies the cancel command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:allow-cancel-all`

</td>
<td>

Enables the cancel_all command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:deny-cancel-all`

</td>
<td>

Denies the cancel_all command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:allow-drain-pending`

</td>
<td>

Enables the drain_pending command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:deny-drain-pending`

</td>
<td>

Denies the drain_pending command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:allow-get-token`

</td>
<td>

Enables the get_token command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:deny-get-token`

</td>
<td>

Denies the get_token command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:allow-notify`

</td>
<td>

Enables the notify command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:deny-notify`

</td>
<td>

Denies the notify command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:allow-request-permission`

</td>
<td>

Enables the request_permission command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-push:deny-request-permission`

</td>
<td>

Denies the request_permission command without any pre-configured scope.

</td>
</tr>
</table>
