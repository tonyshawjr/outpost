<script>
  import { navigate } from '$lib/stores.js';
</script>

<div class="access-denied">
  <div class="access-denied-icon">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
      <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
      <path d="M7 11V7a5 5 0 0110 0v4"/>
    </svg>
  </div>
  <h1 class="access-denied-title">Access Denied</h1>
  <p class="access-denied-text">You don't have permission to view this page. Contact your administrator if you believe this is an error.</p>
  <button class="btn btn-secondary" onclick={() => navigate('dashboard')}>Back to Dashboard</button>
</div>

<style>
  .access-denied {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 50vh;
    text-align: center;
    padding: var(--space-xl);
  }

  .access-denied-icon {
    color: var(--dim);
    margin-bottom: var(--space-lg);
  }

  .access-denied-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: var(--space-sm);
  }

  .access-denied-text {
    font-size: var(--font-size-sm);
    color: var(--sec);
    max-width: 400px;
    margin-bottom: var(--space-xl);
    line-height: 1.5;
  }
</style>
