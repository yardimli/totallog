import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');
const functionSource = name => source.slice(source.indexOf(`function ${name}(`), source.indexOf('\nfunction ', source.indexOf(`function ${name}(`) + 1));
const clickSource = source.slice(source.indexOf('    const timelineItem = e.target.closest'), source.indexOf('    const emptyLogSpace ='));

function fixture() {
    const opened = [], messages = [];
    const element = () => ({dataset:{}, children:[], append(...children) { this.children.push(...children); }, setAttribute() {}, classList:{remove() {}}});
    const item = {kind:'block', time:'12:00', block:{id:'local-1', type:'text', content:'New note', optimistic:true, edit_kind:'block', edit_url:'/logs/2914/blocks'}};
    let row;
    const context = vm.createContext({
        element, formatClock: value => value, activeDayState:{timeline:[item]}, CSS:{escape: value => value},
        document:{createTextNode: value => value, querySelector: () => ({closest: () => row})},
        configureComposer: options => opened.push(options), toast: message => messages.push(message),
        composerTrigger:null, demoReadOnly:false,
    });
    vm.runInContext(functionSource('renderTimelineItem') + functionSource('reconcileOptimisticBlock'), context);
    row = context.renderTimelineItem(item);
    row.matches = selector => selector === '[data-timeline-edit]';
    row.querySelector = () => row.children[1].children[0];
    context.e = {target:{closest: selector => selector === '.timeline-item' ? row : null}};
    return {context, item, row, opened, messages, click: () => vm.runInContext(`{${clickSource}}`, context)};
}

test('a pending note cannot open an editor pointing at the POST-only creation URL', () => {
    const f = fixture();
    f.click();
    assert.equal(f.opened.length, 0);
    assert.match(f.messages[0], /still saving/);
});

test('after creation completes the same row opens the server-provided update URL', () => {
    const f = fixture();
    f.context.reconcileOptimisticBlock('local-1', {block:{id:42}, edit_url:'/blocks/42', hide_url:'/blocks/42/visibility', delete_url:'/blocks/42'});
    f.click();
    assert.equal(f.opened.length, 1);
    assert.equal(f.opened[0].action, '/blocks/42');
    assert.equal(f.opened[0].mode, 'edit');
    assert.equal(f.messages.length, 0);
    assert.equal(f.context.renderTimelineItem(f.item).dataset.pendingCreation, undefined);
});

function dayControlsFixture(mode) {
    const composer = {action:'/blocks/42', dataset:{composerMode:mode}};
    const node = {dataset:{}, append() {}, replaceWith() {}, cloneNode() { return this; }};
    const context = vm.createContext({
        document:{
            querySelector: selector => selector === '[data-composer-note-form]' ? composer
                : ['#daily-log-page-container', '#timeline'].includes(selector) ? node : null,
            createDocumentFragment: () => node,
        },
        updateDayNavigation() {}, updateDayStickyEvents() {}, updateDayGoals() {},
    });
    vm.runInContext(functionSource('updateDayControls'), context);
    return {composer, refresh: () => context.updateDayControls({timeline:[], date:'2026-10-24', log:{create_block_url:'/logs/2914/blocks'}})};
}

test('timeline refreshes preserve the existing entry URL across repeated edits', () => {
    const f = dayControlsFixture('edit');
    f.refresh();
    assert.equal(f.composer.action, '/blocks/42');
    f.refresh();
    assert.equal(f.composer.action, '/blocks/42');
    assert.equal(f.composer.dataset.createAction, '/logs/2914/blocks');
});

test('timeline refreshes still update the destination for new entries', () => {
    for (const mode of ['create', undefined]) {
        const f = dayControlsFixture(mode);
        f.refresh();
        assert.equal(f.composer.action, '/logs/2914/blocks');
        assert.equal(f.composer.dataset.createAction, '/logs/2914/blocks');
    }
});
