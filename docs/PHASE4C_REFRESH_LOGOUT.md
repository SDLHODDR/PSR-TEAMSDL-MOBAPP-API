# Legacy Phase 4C refresh/logout implementation

The opaque access-token, refresh-token rotation, replay detection, and Oracle token-table implementation from Phase 4C is retained in the repository for possible future migration. It is not part of the active initial-release API flow.

The active authentication architecture is documented in [JWT_INITIAL_RELEASE.md](./JWT_INITIAL_RELEASE.md).

The legacy `AuthTokenModel` and token-table DML must not be called by the active login, middleware, logout, or `auth/me` flow.
