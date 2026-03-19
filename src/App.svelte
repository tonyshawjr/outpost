<script>
  import { onMount } from 'svelte';
  import { auth, setCsrfToken } from '$lib/api.js';
  import {
    user,
    isAuthenticated,
    currentRoute,
    darkMode,
    sidebarOpen,
    searchOpen,
    canManageSettings,
    canAccessCodeEditor,
    canManageChannels,
    canBuildForms,
    collectionGrants,
    mediaFolderGrants,
    appVersion,
    updateAvailable,
    latestVersion,
    setupCompleted,
    rangerOpen,
    navigate,
  } from '$lib/stores.js';
  import './styles/admin.css';

  import Login from '$pages/Login.svelte';
  import Dashboard from '$pages/Dashboard.svelte';
  import Pages from '$pages/Pages.svelte';
  import PageEditor from '$pages/PageEditor.svelte';
  import CollectionList from '$pages/CollectionList.svelte';
  import CollectionItems from '$pages/CollectionItems.svelte';
  import CollectionEditor from '$pages/CollectionEditor.svelte';
  import MediaLibrary from '$pages/MediaLibrary.svelte';
  import Settings from '$pages/Settings.svelte';
  import UserProfile from '$pages/UserProfile.svelte';
  import FolderManager from '$pages/FolderManager.svelte';
  import FolderLabels from '$pages/FolderLabels.svelte';
  import FolderLabelEdit from '$pages/FolderLabelEdit.svelte';
  import FolderEdit from '$pages/FolderEdit.svelte';
  import CodeEditor from '$pages/CodeEditor.svelte';
  import TemplateReference from '$pages/TemplateReference.svelte';
  import Brand from '$pages/Brand.svelte';
  import Themes from '$pages/Themes.svelte';
  import Globals from '$pages/Globals.svelte';
  import Navigation from '$pages/Navigation.svelte';
  import Forms from '$pages/Forms.svelte';
  import FormsList from '$pages/FormsList.svelte';
  import FormBuilder from '$pages/FormBuilder.svelte';
  import FormSubmissions from '$pages/FormSubmissions.svelte';
  import Analytics from '$pages/Analytics.svelte';
  import ChannelsList from '$pages/ChannelsList.svelte';
  import ChannelBuilder from '$pages/ChannelBuilder.svelte';
  import Backups from '$pages/Backups.svelte';
  import ThemeCustomizer from '$pages/ThemeCustomizer.svelte';
  import Calendar from '$pages/Calendar.svelte';
  import SetupWizard from '$pages/SetupWizard.svelte';
  import AccessDenied from '$pages/AccessDenied.svelte';
  import Sidebar from '$components/Sidebar.svelte';
  import TopBar from '$components/TopBar.svelte';
  import RightSidebar from '$components/RightSidebar.svelte';
  import MobileNav from '$components/MobileNav.svelte';
  import Toast from '$components/Toast.svelte';
  import SearchModal from '$components/SearchModal.svelte';
  import Ranger from '$components/Ranger.svelte';
  import outpostLogo from './assets/outpost.svg';
  let checking = $state(true);
  let sessionExpired = $state(false);

  onMount(async () => {
    const unsub = darkMode.subscribe((dark) => {
      document.documentElement.classList.toggle('dark', dark);
    });

    const handleSessionExpired = () => { sessionExpired = true; };
    window.addEventListener('outpost:session-expired', handleSessionExpired);

    try {
      const data = await auth.me();
      if (data.user) {
        user.set(data.user);
        setCsrfToken(data.csrf_token);
        if (data.version) appVersion.set(data.version);
        if (data.update_available) {
          updateAvailable.set(true);
          latestVersion.set(data.latest_version);
        }
        collectionGrants.set(data.collection_grants ?? null);
        mediaFolderGrants.set(data.media_folder_grants ?? null);
        // Setup wizard redirect for fresh installs
        const completed = data.setup_completed !== false;
        setupCompleted.set(completed);
        if (!completed) navigate('setup');
      }
    } catch (e) {
      // Not authenticated
    } finally {
      checking = false;
    }

    return () => {
      unsub();
      window.removeEventListener('outpost:session-expired', handleSessionExpired);
    };
  });

  function handleRelogin() {
    window.location.reload();
  }

  // Login is handled by Login.svelte directly setting the user store

  let authenticated = $derived($isAuthenticated);
  let route = $derived($currentRoute);
  let hasSettingsAccess = $derived($canManageSettings);
  let hasCodeAccess = $derived($canAccessCodeEditor);
  let hasChannelsAccess = $derived($canManageChannels);
  let hasFormBuilderAccess = $derived($canBuildForms);

  // Redirect old standalone routes into Settings hub
  $effect(() => {
    const redirectMap = {
      'users': 'team',
      'members': 'members',
      'import': 'import',
      'webhooks': 'integrations',
    };
    if (redirectMap[route]) {
      navigate('settings', { section: redirectMap[route] });
    }
  });
</script>

<svelte:window onkeydown={(e) => {
  if (authenticated && (e.metaKey || e.ctrlKey) && e.key === 'k') {
    e.preventDefault();
    searchOpen.set(true);
  }
}} />

{#if authenticated}
  <SearchModal />
{/if}

{#if checking}
  <div class="loading-overlay">
    <div class="spinner"></div>
  </div>
{:else if !authenticated}
  <Login />
{:else if route === 'setup'}
  <SetupWizard />
{:else}
  <div class="app-layout" class:no-right-sidebar={route !== 'collection-editor'} class:ranger-open={$rangerOpen}>
    <Sidebar />
    <div class="app-main">
      <TopBar />
      <div class="app-content" class:editor-active={route === 'collection-editor' || route === 'code-editor' || route === 'page-editor' || route === 'theme-customizer'}>
        {#if route === 'analytics' || route === 'analytics-events' || route === 'analytics-goals' || route === 'analytics-search' || route === 'analytics-content' || route === 'analytics-funnels'}
          {#if hasCodeAccess}
            <Analytics />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'dashboard'}
          <Dashboard />
        {:else if route === 'calendar'}
          <Calendar />
        {:else if route === 'pages'}
          <Pages />
        {:else if route === 'page-editor'}
          <PageEditor />
        {:else if route === 'collections'}
          <CollectionList />
        {:else if route === 'collection-items'}
          <CollectionItems />
        {:else if route === 'collection-editor'}
          <CollectionEditor />
        {:else if route === 'media'}
          <MediaLibrary />
        {:else if route === 'globals'}
          <Globals />
        {:else if route === 'navigation'}
          <Navigation />
        {:else if route === 'forms'}
          <Forms />
        {:else if route === 'forms-list'}
          <FormsList />
        {:else if route === 'form-builder'}
          {#if hasFormBuilderAccess}
            <FormBuilder />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'form-submissions'}
          <FormSubmissions />
        {:else if route === 'channels'}
          {#if hasChannelsAccess}
            <ChannelsList />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'channel-builder'}
          {#if hasChannelsAccess}
            <ChannelBuilder />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'settings'}
          {#if hasSettingsAccess}
            <Settings />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'user-profile'}
          <UserProfile />
        {:else if route === 'folder-manager'}
          <FolderManager />
        {:else if route === 'folder-labels'}
          <FolderLabels />
        {:else if route === 'folder-label-edit'}
          <FolderLabelEdit />
        {:else if route === 'folder-edit'}
          <FolderEdit />
        {:else if route === 'code-editor'}
          {#if hasCodeAccess}
            <CodeEditor />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'template-reference'}
          {#if hasCodeAccess}
            <TemplateReference />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'themes'}
          {#if hasSettingsAccess}
            <Themes />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'theme-customizer'}
          {#if hasSettingsAccess}
            <ThemeCustomizer />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'brand'}
          {#if hasSettingsAccess}
            <Brand />
          {:else}
            <AccessDenied />
          {/if}
        {:else if route === 'backups'}
          {#if hasSettingsAccess}
            <Backups />
          {:else}
            <AccessDenied />
          {/if}
        {:else}
          <Dashboard />
        {/if}

        {#if route !== 'collection-editor' && route !== 'template-reference' && route !== 'code-editor' && route !== 'page-editor' && route !== 'theme-customizer'}
          <!-- Watermark Footer -->
          <div class="watermark-footer">
            <div class="watermark-text">Handcrafted with 🫀 in Wilmington, NC</div>
            <div class="watermark-logo-row">
              <div class="watermark-logo-wrap">
                <img src={outpostLogo} alt="" class="watermark-logo" aria-hidden="true" />
                {#if $appVersion}
                  <span class="watermark-version-pill">v{$appVersion}</span>
                {/if}
              </div>
              <a href="/outpost/docs/" target="_blank" rel="noopener" class="watermark-docs-link">Developer Documentation →</a>
            </div>
          </div>
        {/if}
      </div>
    </div>
    <RightSidebar />
  </div>
  <Ranger open={$rangerOpen} onclose={() => rangerOpen.set(false)} />
  <MobileNav />
{/if}

{#if sessionExpired}
  <div class="session-expired-overlay" onclick={handleRelogin}>
    <div class="session-expired-modal" onclick={(e) => e.stopPropagation()}>
      <div class="session-expired-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <h2 class="session-expired-title">Session expired</h2>
      <p class="session-expired-text">Your session has timed out. Please log in again to continue.</p>
      <button class="btn btn-primary session-expired-btn" onclick={handleRelogin}>Log in</button>
    </div>
  </div>
{/if}

<Toast />
