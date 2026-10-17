export async function waitForDialogReady(page, dialog, timeout = 3000) {
    await dialog.waitFor({ timeout });
    await dialog.evaluate(node => Promise.all(node.getAnimations().map(animation => animation.finished)));
}
