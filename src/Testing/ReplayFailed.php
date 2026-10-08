<?php

namespace NativeBlade\Testing;

/**
 * Raised inside a replayed run when it diverges from the previous one or
 * exceeds a budget. An Error so app code cannot swallow it; the replay loop
 * turns it into a PHPUnit failure with the message.
 */
final class ReplayFailed extends \Error
{
}
