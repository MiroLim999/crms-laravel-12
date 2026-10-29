{{--
    The scan flow's top bar, shared by New Document (no type yet) and the
    workspace, so choosing a type does not change the frame around the page.

    $cancelable: the workspace passes true. Cancel and the type-and-file line
    stay hidden until a scan is open (resources/views/scan/workspace.blade.php,
    showStep), because on the Document step there is nothing to cancel yet.
--}}
<nav class="layout-navbar container-xxl navbar-detached navbar document-workspace-topbar"
     id="layout-navbar" aria-label="Document workspace">
    <div class="document-workspace-topbar__context">
        <small id="workspaceContextDetail" class="d-none"></small>
    </div>

    <nav class="document-flow-steps" id="documentFlowSteps" aria-label="Document processing progress">
        <div class="document-flow-step is-active" data-flow-step="upload" aria-current="step">
            <span class="document-flow-step__number">1</span>
            <span class="document-flow-step__copy"><strong>Document</strong><small>Type and scan</small></span>
        </div>
        <div class="document-flow-step" data-flow-step="mark">
            <span class="document-flow-step__number">2</span>
            <span class="document-flow-step__copy"><strong>Align</strong><small>Check the markers</small></span>
        </div>
        <div class="document-flow-step" data-flow-step="verify">
            <span class="document-flow-step__number">3</span>
            <span class="document-flow-step__copy"><strong>Verify</strong><small>Review and submit</small></span>
        </div>
    </nav>

    @if ($cancelable ?? false)
        <a href="{{ route('documents.create') }}" id="workspaceCancel"
           class="btn btn-sm btn-outline-secondary document-workspace-topbar__cancel d-none">Cancel</a>
    @endif
</nav>
