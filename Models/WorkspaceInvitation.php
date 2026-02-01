<?php

declare(strict_types=1);

namespace Core\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkspaceInvitation extends Model
{
    use HasFactory;
    use Notifiable;

    protected static function newFactory(): \Core\Tenant\Database\Factories\WorkspaceInvitationFactory
    {
        return \Core\Tenant\Database\Factories\WorkspaceInvitationFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'email',
        'token',
        'token_hint',
        'role',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * The "booted" method of the model.
     *
     * Automatically hashes tokens and stores hint when creating invitations.
     */
    protected static function booted(): void
    {
        static::creating(function (WorkspaceInvitation $invitation) {
            // Only hash if the token looks like a plaintext token (not already hashed)
            // Bcrypt hashes start with $2y$ and are 60 chars
            if ($invitation->token && ! str_starts_with($invitation->token, '$2y$')) {
                // Store first 8 chars as hint for indexed lookup before hashing
                $invitation->token_hint = substr($invitation->token, 0, 8);
                $invitation->token = Hash::make($invitation->token);
            }
        });
    }

    /**
     * Get the workspace this invitation is for.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the user who sent this invitation.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Scope to pending invitations (not accepted, not expired).
     */
    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to expired invitations.
     */
    public function scopeExpired($query)
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope to accepted invitations.
     */
    public function scopeAccepted($query)
    {
        return $query->whereNotNull('accepted_at');
    }

    /**
     * Check if invitation is pending (not accepted and not expired).
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * Check if invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /**
     * Check if invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Generate a unique token for this invitation.
     *
     * Returns the plaintext token. The token will be hashed when stored.
     */
    public static function generateToken(): string
    {
        // Generate a cryptographically secure random token
        // No need to check for uniqueness since hashed tokens are unique
        return Str::random(64);
    }

    /**
     * Find invitation by token.
     *
     * Uses token_hint for indexed lookup, then verifies with bcrypt.
     * Falls back to full scan for invitations without hints (legacy).
     */
    public static function findByToken(string $token): ?self
    {
        $hint = substr($token, 0, 8);

        // First, try indexed lookup using token_hint
        $candidates = static::where('token_hint', $hint)
            ->orderByDesc('created_at')
            ->get();

        foreach ($candidates as $invitation) {
            if (Hash::check($token, $invitation->token)) {
                return $invitation;
            }
        }

        // Fallback: check legacy invitations without token_hint (limit scan)
        $legacyInvitations = static::whereNull('token_hint')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        foreach ($legacyInvitations as $invitation) {
            if (Hash::check($token, $invitation->token)) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * Find pending invitation by token.
     *
     * Uses token_hint for indexed lookup, then verifies with bcrypt.
     * Falls back to full scan for invitations without hints (legacy).
     */
    public static function findPendingByToken(string $token): ?self
    {
        $hint = substr($token, 0, 8);

        // First, try indexed lookup using token_hint
        $candidates = static::pending()
            ->where('token_hint', $hint)
            ->get();

        foreach ($candidates as $invitation) {
            if (Hash::check($token, $invitation->token)) {
                return $invitation;
            }
        }

        // Fallback: check legacy pending invitations without token_hint
        $legacyInvitations = static::pending()
            ->whereNull('token_hint')
            ->get();

        foreach ($legacyInvitations as $invitation) {
            if (Hash::check($token, $invitation->token)) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * Verify if the given plaintext token matches this invitation's hashed token.
     */
    public function verifyToken(string $plaintextToken): bool
    {
        return Hash::check($plaintextToken, $this->token);
    }

    /**
     * Accept the invitation for a user.
     */
    public function accept(User $user): bool
    {
        if (! $this->isPending()) {
            return false;
        }

        // Check if user already belongs to this workspace
        if ($this->workspace->users()->where('user_id', $user->id)->exists()) {
            // Mark as accepted but don't add again
            $this->update(['accepted_at' => now()]);

            return true;
        }

        // Add user to workspace with the invited role
        $this->workspace->users()->attach($user->id, [
            'role' => $this->role,
            'is_default' => false,
        ]);

        // Mark invitation as accepted
        $this->update(['accepted_at' => now()]);

        return true;
    }

    /**
     * Get the notification routing for mail.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
