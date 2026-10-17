export async function waitForDialogReady(page, dialog, timeout = 3000) {
    await dialog.waitFor({ timeout });
    const popup = await dialog.elementHandle({ timeout });
    if (!popup) {
        throw new Error('Dialog disappeared before opening completed.');
    }
    try {
        // The popup can be attached before its opening animation is scheduled.
        const ready = await page.waitForFunction(node => (
            node.isConnected && getComputedStyle(node).opacity === '1'
            && node.getAnimations().every(animation => !animation.pending && animation.playState !== 'running')
        ), popup, { timeout });
        await ready.dispose();
    } finally {
        await popup.dispose();
    }
}
