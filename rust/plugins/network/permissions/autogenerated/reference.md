## Default Permission

Default permission set for the NativeBlade network plugin.
Allows reading connectivity status; change events need no permission.

#### This default permission set includes the following:

- `allow-get-status`
- `allow-register-listener`

## Permission Table

<table>
<tr>
<th>Identifier</th>
<th>Description</th>
</tr>


<tr>
<td>

`nativeblade-network:allow-get-status`

</td>
<td>

Enables the get_status command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-network:deny-get-status`

</td>
<td>

Denies the get_status command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-network:allow-register-listener`

</td>
<td>

Enables the register_listener command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-network:deny-register-listener`

</td>
<td>

Denies the register_listener command without any pre-configured scope.

</td>
</tr>
</table>
