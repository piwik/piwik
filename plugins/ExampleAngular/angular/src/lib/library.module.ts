declare var angular: angular.IAngularStatic;

import {Component, NgModule, OnInit, StaticProvider} from '@angular/core';
import {downgradeComponent, downgradeModule, UpgradeModule} from '@angular/upgrade/static';
import { platformBrowserDynamic } from '@angular/platform-browser-dynamic';
import {BrowserModule} from "@angular/platform-browser";

@Component({
  selector: 'lib-library',
  template: `
    <p>
      library works!
    </p>
  `,
  styles: [
  ]
})
export class LibraryComponent implements OnInit {

  constructor() { }

  ngOnInit(): void {

  }

}

@NgModule({
  declarations: [
    LibraryComponent
  ],
  imports: [
    UpgradeModule,
    BrowserModule,
  ],
  entryComponents: [
    LibraryComponent
  ],
  exports: [
      LibraryComponent,
  ],
})
export class LibraryModule {
  ngDoBootstrap() {

  }
}

const ng2BootstrapFn = (extraProviders: StaticProvider[]) =>
    platformBrowserDynamic(extraProviders).bootstrapModule(LibraryModule);

export const angularModuleName = downgradeModule(ng2BootstrapFn);

angular.module(angularModuleName).directive('libLibrary', downgradeComponent({ component: LibraryComponent, downgradedModule: angularModuleName }));
