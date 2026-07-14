/**
 * Twig Inspector Bundle - Main TypeScript file
 * Handles template inspection overlay and interaction
 */

import './style.scss';

interface Template {
  name: string;
  link: string;
}

interface Block {
  index: number;
  element: HTMLElement;
  templates: Template[];
  toString(): string;
}

class TemplateClass implements Template {
  constructor(public name: string, public link: string) {}
}

class BlockClass implements Block {
  constructor(
    public index: number,
    public element: HTMLElement,
    public templates: Template[]
  ) {}

  toString(): string {
    let text = '';
    for (let i = 0; i < this.templates.length; i++) {
      const template = this.templates[i];
      if (text.length > 0) {
        text += '<br/>';
      }
      text += template.name;
    }
    return text;
  }
}

class BlockStorage {
  private elements: HTMLElement[] = [];
  private templatesToElements: Template[][] = [];

  collectData(): void {
    this.elements = [];
    this.templatesToElements = [];

    const sfToolbar = document.getElementsByClassName('sf-toolbar')[0] as HTMLElement;
    const startComments = document.createNodeIterator(
      document.body,
      NodeFilter.SHOW_COMMENT
    );

    let curNode: Node | null;
    while ((curNode = startComments.nextNode())) {
      const match = curNode.nodeValue?.match(
        /^(\s+[\u250F\u256D\u2554\u250E]([^\s]?)+\s)([^\s]+)(\s\[)([^\]]+)(\]\s#)(\w+)/
      );

      if (null === match) {
        continue;
      }

      const templateId = match[7];
      const templateName = match[3] + ' [' + match[5].replace(/\/_template\//g, '') + ']';
      const templateLink = match[5];
      let element: Node | null = curNode.nextSibling;
      const regexp = new RegExp(
        '^(\\s+[\\u2517\\u2570\\u255A\\u2516])([^#]+)(#' + templateId + ')$',
        'g'
      );

      while (!(!element || (element.nodeType === 8 && element.nodeValue?.match(regexp)))) {
        if (
          element.nodeType === 1 &&
          !['SCRIPT', 'STYLE'].includes((element as HTMLElement).tagName) &&
          !sfToolbar.contains(element as HTMLElement) &&
          window.getComputedStyle(element as HTMLElement).display !== 'none'
        ) {
          const layoutItem = this.findOrCreate(element as HTMLElement);
          const template = new TemplateClass(templateName, templateLink);
          this.addTemplate(layoutItem.index, template);
        }
        element = element.nextSibling;
      }
    }
  }

  find(element: HTMLElement): Block | null {
    const index = this.elements.indexOf(element);
    if (index < 0) {
      return null;
    }

    const templates = this.templatesToElements[index];

    return new BlockClass(index, element, templates);
  }

  create(element: HTMLElement): Block {
    const length = this.elements.push(element);
    const index = length - 1;
    this.templatesToElements[index] = [];

    return new BlockClass(index, element, []);
  }

  findOrCreate(element: HTMLElement): Block {
    let layoutItem = this.find(element);

    if (null === layoutItem) {
      layoutItem = this.create(element);
    }

    return layoutItem;
  }

  addTemplate(index: number, template: Template): void {
    this.templatesToElements[index].push(template);
  }

  getTemplates(index: number): Template[] {
    return this.templatesToElements[index];
  }
}

class Overlay {
  public isEnabled: boolean = false;
  public block: HTMLDivElement;
  public info: HTMLDivElement;
  private lastFocusedElement: HTMLElement | null = null;

  constructor(
    private storage: BlockStorage,
    private statusIcon: HTMLElement
  ) {
    this.block = document.createElement('DIV');
    this.block.id = '_twig_inspector__overlay__block';
    document.body.appendChild(this.block);

    this.info = document.createElement('DIV');
    this.info.id = '_twig_inspector__overlay__info';
    document.body.appendChild(this.info);
  }

  hide(): void {
    this.info.classList.remove('_twig_inspector__visible');
    this.block.classList.remove('_twig_inspector__visible');
  }

  show(layoutItem: Block): void {
    const element = layoutItem.element;

    const width = element.offsetWidth;
    const height = element.offsetHeight;
    const left = element.getBoundingClientRect().left;
    const top = element.getBoundingClientRect().top + window.scrollY;

    this.block.style.width = width + 'px';
    if (height !== null) {
      this.block.style.height = height + 'px';
    }
    this.block.style.left = left + 'px';
    this.block.style.top = top + 'px';

    this.block.dataset.templateIndex = layoutItem.index.toString();

    this.info.innerHTML = layoutItem.toString();

    if (top + height + 50 < window.innerHeight + window.scrollY) {
      this.info.style.top = top + height + 2 + 'px';
    } else {
      this.info.style.top = top - this.info.offsetHeight - 2 + 'px';
    }

    if (left + this.info.offsetWidth < window.innerWidth) {
      this.info.style.left = left + 'px';
      this.info.style.right = 'auto';
    } else {
      this.info.style.left = 'auto';
      this.info.style.right = '0';
    }

    this.block.classList.add('_twig_inspector__visible');
    this.info.classList.add('_twig_inspector__visible');
  }

  freeze(): void {
    this.info.classList.remove('_twig_inspector__visible');
    document.body.removeEventListener('mousemove', this.onMouseMove);
  }

  enable(): void {
    document.body.addEventListener('mousemove', this.onMouseMove);
    this.isEnabled = true;

    this.storage.collectData();
    this.statusIcon.classList.add('sf-toolbar-status-green');
    this.statusIcon.classList.remove('sf-toolbar-status-yellow');
  }

  reset(): void {
    this.freeze();
    this.info.classList.remove('_twig_inspector__visible');
    this.block.classList.remove('_twig_inspector__visible');
    this.block.classList.remove('_twig_inspector__overlay__block_static');
    this.block.innerHTML = '';
    this.statusIcon.classList.remove('sf-toolbar-status-green');
    this.statusIcon.classList.add('sf-toolbar-status-yellow');
    this.isEnabled = false;
  }

  private onMouseMove = (event: MouseEvent): void => {
    const sfToolbar = document.getElementsByClassName('sf-toolbar')[0] as HTMLElement;
    const elements = document.elementsFromPoint(event.clientX, event.clientY);

    for (let i = 0; i < elements.length; i++) {
      const element = elements[i] as HTMLElement;
      if (sfToolbar.contains(element)) {
        this.hide();
      }
      const layoutItem = this.storage.find(element);

      if (null !== layoutItem) {
        if (this.lastFocusedElement === element) {
          return;
        }
        this.lastFocusedElement = element;
        return this.show(layoutItem);
      }
    }

    this.hide();
  };

  initClickHandler(): void {
    this.block.addEventListener('click', (event: MouseEvent) => {
      if (this.block.classList.contains('_twig_inspector__overlay__block_static')) {
        return;
      }

      const templateIndex = this.block.dataset.templateIndex;
      if (!templateIndex) {
        return;
      }

      const templates = this.storage.getTemplates(parseInt(templateIndex, 10));

      if (templates.length === 1) {
        this.reset();
        window.location.href = templates[0].link;
        event.stopPropagation();
      } else {
        for (let i = 0; i < templates.length; i++) {
          const template = templates[i];

          const link = document.createElement('div');
          link.dataset.href = template.link;
          link.innerText = template.name;
          link.addEventListener('click', (event: MouseEvent) => {
            this.reset();
            window.location.href = (event.currentTarget as HTMLElement).dataset.href || '';
            event.stopPropagation();
          });
          this.block.appendChild(link);
          this.block.classList.add('_twig_inspector__overlay__block_static');
        }
        this.block.style.left = event.clientX - 20 + 'px';
        this.block.style.right = 'auto';
        this.block.style.top = event.clientY + window.scrollY - 20 + 'px';
      }

      this.freeze();
      event.stopPropagation();
    });
  }

  initKeyboardHandler(): void {
    document.onkeydown = (evt: KeyboardEvent | null) => {
      evt = evt || window.event as KeyboardEvent;
      if (evt.keyCode === 27) {
        this.reset();
      }
    };
  }
}

// Initialize when DOM is ready
(function (): void {
  const statusCheckbox = document.getElementById('_twig_inspector__status') as HTMLInputElement | null;
  if (!statusCheckbox) {
    return;
  }

  statusCheckbox.addEventListener('click', (event: Event) => {
    const target = event.target as HTMLInputElement;
    const isActive = target.checked === true;
    document.cookie = 'twig_inspector_is_active=' + isActive + ';path=/';
    location.reload();
  });

  if (statusCheckbox.checked === false) {
    return;
  }

  const statusIcon = document.getElementById('_twig_inspector__icon');
  if (!statusIcon) {
    return;
  }

  statusIcon.addEventListener('click', () => {
    if (overlay.isEnabled) {
      overlay.reset();
    } else {
      overlay.enable();
    }
  });

  const storage = new BlockStorage();
  const overlay = new Overlay(storage, statusIcon);
  overlay.initClickHandler();
  overlay.initKeyboardHandler();
})();

