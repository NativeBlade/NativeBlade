## Default Permission

Default permission set for the NativeBlade tasks plugin.
Allows reading parked task results, draining handler queues, registering
the task manifest and enqueueing runtime payloads.

#### This default permission set includes the following:

- `allow-get-task`
- `allow-drain-results`
- `allow-register-tasks`
- `allow-enqueue-task`

## Permission Table

<table>
<tr>
<th>Identifier</th>
<th>Description</th>
</tr>


<tr>
<td>

`nativeblade-tasks:allow-drain-results`

</td>
<td>

Enables the drain_results command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:deny-drain-results`

</td>
<td>

Denies the drain_results command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:allow-enqueue-task`

</td>
<td>

Enables the enqueue_task command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:deny-enqueue-task`

</td>
<td>

Denies the enqueue_task command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:allow-get-task`

</td>
<td>

Enables the get_task command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:deny-get-task`

</td>
<td>

Denies the get_task command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:allow-register-tasks`

</td>
<td>

Enables the register_tasks command without any pre-configured scope.

</td>
</tr>

<tr>
<td>

`nativeblade-tasks:deny-register-tasks`

</td>
<td>

Denies the register_tasks command without any pre-configured scope.

</td>
</tr>
</table>
