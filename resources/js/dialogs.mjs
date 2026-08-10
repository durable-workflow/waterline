function mergeClassNames(...classNames) {
    return classNames
        .filter((className) => typeof className === 'string' && className.trim().length > 0)
        .map((className) => className.trim())
        .join(' ')
}

function dialogDocument() {
    return typeof document === 'undefined' ? null : document
}

export function createWaterlineDialogOptions(theme, options = {}, ownerDocument = dialogDocument()) {
    const {
        customClass = {},
        didOpen,
        willClose,
        ...dialogOptions
    } = options
    let inertRoot = null
    let rootWasInert = false

    return {
        ...dialogOptions,
        background: theme === 'light' ? '#ffffff' : '#181818',
        customClass: {
            ...customClass,
            popup: mergeClassNames('waterline-dialog', customClass.popup),
            htmlContainer: mergeClassNames('waterline-dialog__body', customClass.htmlContainer),
            validationMessage: mergeClassNames('waterline-dialog__validation', customClass.validationMessage),
            actions: mergeClassNames('waterline-dialog__actions', customClass.actions),
            confirmButton: mergeClassNames('waterline-dialog__confirm', customClass.confirmButton),
            cancelButton: mergeClassNames('waterline-dialog__cancel', customClass.cancelButton),
            denyButton: mergeClassNames('waterline-dialog__deny', customClass.denyButton),
        },
        didOpen(popup) {
            popup.setAttribute('role', 'dialog')
            popup.setAttribute('aria-modal', 'true')
            popup.setAttribute('data-waterline-dialog', 'modal')

            const container = popup.closest('.swal2-container')
            container?.setAttribute('data-waterline-modal-backdrop', 'intentional')

            const appRoot = ownerDocument?.getElementById('waterline')

            if (appRoot && !appRoot.contains(popup)) {
                inertRoot = appRoot
                rootWasInert = appRoot.hasAttribute('inert')
                appRoot.setAttribute('inert', '')
            }

            didOpen?.(popup)
        },
        willClose(popup) {
            try {
                willClose?.(popup)
            } finally {
                if (inertRoot && !rootWasInert) {
                    inertRoot.removeAttribute('inert')
                }

                inertRoot = null
            }
        },
    }
}
