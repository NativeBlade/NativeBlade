## Default Permission

Default permission set for the NativeBlade push notifications plugin.
Allows querying the device token, requesting notification permission,
and draining pushes that were buffered during cold start.

#### This default permission set includes the following:

- `allow-get-token`
- `allow-request-permission`
- `allow-drain-pending`

## Permission Table

<table>
<tr>
<th>Identifier</th>
<th>Description</th>
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
