jQuery(document).ready(function($) {
  function registerListener(el) {
    const parentEl = $(el);
    const removeEl = parentEl.find(".remove");
    removeEl.click(e => {
      e.stopPropagation();
      parentEl.remove();
    });
  }
  $("#redirectList li.redirect").each((i, el) => el && registerListener(el));
  $("#addButton").click(e => {
    e.stopPropagation();
    const count = $("#redirectList li.redirect").length;
    const newEl = $("#redirectList").append(
      `<li class="redirect"><input name="fromPath-${count}"><input name="toPath-${count}"><span class="remove">❌</span></li>`
    );
    registerListener(newEl.find("li:last-child"));
  });
});
