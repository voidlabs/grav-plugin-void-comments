(() => {
  const section = document.querySelector('.comments');
  if (!section) return;
  const form = section.querySelector('form');
  const parent = form?.querySelector('input[name="data[parent_id]"]');
  const context = section.querySelector('[data-comment-reply-context]');
  const author = context?.querySelector('[data-comment-reply-author]');
  const cancel = context?.querySelector('[data-comment-reply-cancel]');
  if (!form || !parent || !context || !author || !cancel) return;

  const showReply = (id, name) => {
    parent.value = id;
    author.textContent = name;
    context.hidden = false;
    form.classList.add('is-replying');
  };
  const clearReply = () => {
    parent.value = '';
    author.textContent = '';
    context.hidden = true;
    form.classList.remove('is-replying');
    const url = new URL(window.location.href);
    url.searchParams.delete('reply_to');
    history.replaceState(null, '', url.pathname + url.search + url.hash);
  };

  section.querySelectorAll('[data-comment-reply]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showReply(link.dataset.commentReply || '', link.dataset.commentAuthor || '');
      form.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
      form.querySelector('textarea')?.focus({ preventScroll: true });
    });
  });
  cancel.addEventListener('click', clearReply);
  const initialParent = parent.value || context.dataset.commentReplyId || '';
  if (initialParent) showReply(initialParent, author.textContent || 'commento selezionato');
})();
